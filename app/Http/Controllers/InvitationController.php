<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner') || $request->user()->hasRole('admin'), 403);
        $query = DB::table('school_invitations')->whereNull('accepted_at');
        if (! $request->user()->hasRole('owner')) {
            $query->where('roles', 'not like', '%"admin"%');
        }

        return response()->json(['rows' => $query->orderByDesc('id')->paginate(30, ['id', 'name', 'email', 'roles', 'expires_at'])->through(function (object $invitation): object {
            $invitation->roles = json_decode($invitation->roles, true);
            $invitation->is_pending = $invitation->expires_at > now()->toDateTimeString();

            return $invitation;
        })]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner') || $request->user()->hasRole('admin'), 403);
        DB::transaction(function () use ($request, $id): void {
            $invitation = DB::table('school_invitations')->where('id', $id)->first();
            abort_unless($invitation, 404);
            abort_if(in_array('admin', json_decode($invitation->roles, true), true) && ! $request->user()->hasRole('owner'), 403);
            abort_if($invitation->accepted_at !== null, 409, 'This invitation was already accepted. Manage the account from People & access.');
            DB::table('school_invitations')->where('id', $id)->update(['expires_at' => now(), 'token_hash' => hash('sha256', Str::random(64)), 'updated_at' => now()]);
            DB::table('school_audit')->insert(['user_id' => $request->user()->id, 'module' => 'invitations', 'record_id' => $id, 'action' => 'revoked', 'changes' => json_encode(['email' => $invitation->email]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Invitation revoked. The old link no longer works.']);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner') || $request->user()->hasRole('admin'), 403);
        $allowed = ['teacher', 'student', 'parent', 'accountant'];
        if ($request->user()->hasRole('owner')) {
            $allowed[] = 'admin';
        }
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in($allowed), 'distinct']]);
        $existing = DB::table('school_invitations')->where('email', $data['email'])->first();
        if ($existing) {
            $oldRoles = json_decode($existing->roles, true);
            abort_if(in_array('admin', $oldRoles, true) && ! $request->user()->hasRole('owner'), 403);
        }
        $token = Str::random(64);
        DB::transaction(function () use ($data, $token, $request): void {
            DB::table('school_invitations')->updateOrInsert(['email' => $data['email']], [
                'name' => $data['name'], 'roles' => json_encode($data['roles']), 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48), 'accepted_at' => null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('school_audit')->insert(['user_id' => $request->user()->id, 'module' => 'invitations', 'record_id' => 0, 'action' => 'issued',
                'changes' => json_encode(['email' => $data['email'], 'roles' => $data['roles']]), 'created_at' => now()]);
        });

        return response()->json(['url' => route('invitation.show', ['token' => $token]), 'message' => 'Share this single-use link privately with the verified recipient. It expires in 48 hours. Creating another invitation for this email revokes the old link.']);
    }

    public function show(string $token): View
    {
        $invitation = $this->find($token);

        return view('auth.invitation', compact('invitation', 'token'));
    }

    private function find(string $token): object
    {
        abort_unless(strlen($token) === 64, 404);
        $invitation = DB::table('school_invitations')->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->first();
        abort_unless($invitation, 404, 'This invitation has expired or has already been used.');
        $creator = User::find($invitation->created_by);
        $roles = json_decode($invitation->roles, true);
        abort_unless($creator && ($creator->hasRole('owner') || ($creator->hasRole('admin') && ! in_array('admin', $roles, true))), 404);

        return $invitation;
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate(['username' => ['required', 'regex:/^[a-z0-9._-]{3,80}$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:10', 'max:72', 'confirmed']]);
        DB::transaction(function () use ($token, $data): void {
            $invitation = $this->find($token);
            abort_if(User::where('email', $invitation->email)->exists(), 409, 'This email already has an account. Ask your administrator to update its access.');
            $claimed = DB::table('school_invitations')->where('id', $invitation->id)->whereNull('accepted_at')->update(['accepted_at' => now(), 'updated_at' => now()]);
            abort_unless($claimed === 1, 409);
            $user = new User;
            $user->forceFill(['name' => $invitation->name, 'email' => $invitation->email, 'username' => $data['username'],
                'password' => $data['password'], 'roles' => json_decode($invitation->roles, true), 'is_active' => true])->save();
        });

        return redirect()->route('login')->with('status', 'Your account is ready. Sign in with your new username and password.');
    }
}
