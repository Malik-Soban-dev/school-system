let seenGuides = [];
function login(role) {
    cy.intercept('GET','/portal/meta').as('loadMeta');
    cy.visit('/login'); cy.get('#username').type('test.' + role); cy.get('#password').type('Browser-test-12345', {log:false}); cy.get('button[type=submit]').click(); cy.url().should('include','/dashboard');
    cy.wait('@loadMeta').then(({response}) => {seenGuides = response.body.user.tutorials ?? []; if(!seenGuides.includes('overview')) cy.contains('button','Skip guide').click();});
}
function open(key) {
    cy.get(`[data-cy=nav-${key}]`).click();
    cy.then(() => { if (!seenGuides.includes(key)) { cy.contains('button','Skip guide').click(); seenGuides.push(key); } });
}
describe('School workflows', () => {
    it('owner creates a subject and replays contextual help', () => {
        login('owner'); open('subjects'); cy.get('[data-cy=add-record]').click(); cy.get('#field-name').type('English'); cy.get('#field-code').type('ENG'); cy.contains('button','Save record').click(); cy.contains('[data-cy=record]','English').should('be.visible');
        cy.contains('button','Page guide').click(); cy.contains('STEP 1 OF 4').should('be.visible'); cy.contains('button','Next tip').click(); cy.contains('Find what you need').should('be.visible'); cy.contains('button','Skip guide').click(); cy.get('dialog').should('not.exist'); cy.document().then(doc => expect(doc.documentElement.scrollWidth).to.be.at.most(1280)); cy.screenshot('owner-desktop', {capture:'viewport'});
    });
    it('parent sees only their child on mobile without horizontal scrolling', () => {
        cy.viewport(390,844); login('parent'); cy.contains('button','Menu').click(); open('students'); cy.contains('Alex Student').should('be.visible'); cy.contains('Private Student').should('not.exist'); cy.get('[data-cy=add-record]').should('not.exist');
        cy.document().then(doc => expect(doc.documentElement.scrollWidth).to.be.at.most(390)); cy.screenshot('parent-mobile');
        cy.get('body').invoke('text').should('not.match', /[\u00c3\u00c2]/);
        cy.contains('button','Menu').click(); open('invoices'); cy.contains('Outstanding balance').should('be.visible'); cy.contains('100.10').should('be.visible');
    });
    it('teacher records attendance and validation blocks duplicate entries', () => {
        login('teacher'); open('attendance'); cy.get('[data-cy=add-record]').click(); cy.get('#field-student_id').select('Alex Student'); const today=new Date().toISOString().slice(0,10); cy.get('#field-date').type(today); cy.get('#field-status').select('present'); cy.contains('button','Save record').click(); cy.contains('[data-cy=record]','Alex Student').should('be.visible');
        cy.get('[data-cy=add-record]').click(); cy.get('#field-student_id').select('Alex Student'); cy.get('#field-date').type(today); cy.contains('button','Save record').click(); cy.contains('This record already exists.').should('be.visible'); cy.contains('button','Cancel').click();
    });
    it('accountant records payment and rejects overpayment', () => {
        login('accountant'); open('payments'); cy.get('[data-cy=add-record]').click(); cy.get('#field-invoice_id').select('INV-001'); cy.get('#field-reference').type('CYP-001'); cy.get('#field-amount').type('100.11'); cy.get('#field-paid_on').type(new Date().toISOString().slice(0,10)); cy.contains('button','Save record').click(); cy.contains('Payment must be greater than zero').should('be.visible'); cy.get('#field-amount').clear().type('40.05'); cy.contains('button','Save record').click(); cy.contains('[data-cy=record]','CYP-001').should('be.visible'); open('invoices'); cy.contains('60.05').should('be.visible');
    });
    it('student cannot open administrative endpoints', () => {
        login('student'); cy.request({url:'/portal/users',failOnStatusCode:false,headers:{Accept:'application/json'}}).its('status').should('eq',403); cy.request({url:'/portal/records/payroll',failOnStatusCode:false,headers:{Accept:'application/json'}}).its('status').should('eq',403);
    });
    it('teacher saves a class roster on a small phone', () => {
        cy.viewport(360,800); login('teacher'); cy.contains('button','Menu').click(); open('attendance'); cy.get('#attendance-class').select('Grade 5 A'); cy.contains('button','Quick attendance').click(); cy.get('#attendance-1').select('late'); cy.get('#attendance-2').select('present'); cy.contains('button','Save attendance').click(); cy.contains('Attendance saved for 2 students.').should('be.visible'); cy.contains('[data-cy=record]','Alex Student').should('contain','late'); cy.document().then(doc => expect(doc.documentElement.scrollWidth).to.be.at.most(360)); cy.screenshot('teacher-mobile');
    });
    it('owner invites a teacher who activates their own account', () => {
        login('owner'); cy.contains('nav button','People & access').click(); cy.contains('button','Skip guide').click(); cy.contains('button','Invite person').click(); cy.get('#invite-name').type('Invited Teacher'); cy.get('#invite-email').type('invited@example.test'); cy.contains('button','Create private invitation').click(); cy.get('#invitation-link').invoke('val').then(url => {
            cy.get('dialog[open]').within(() => cy.get('button[aria-label]').click());
            cy.contains('button','Sign out').click(); cy.visit(url); cy.get('#username').type('invited.teacher'); cy.get('#password').type('Invite-test-12345',{log:false}); cy.get('#password_confirmation').type('Invite-test-12345',{log:false}); cy.get('button[type=submit]').click(); cy.url().should('include','/login'); cy.get('#username').type('invited.teacher'); cy.get('#password').type('Invite-test-12345',{log:false}); cy.get('button[type=submit]').click(); cy.contains('Welcome, Invited Teacher').should('be.visible');
        });
    });
    it('owner saves first-use school settings', () => {
        login('owner'); cy.contains('nav button','School settings').click(); cy.contains('button','Skip guide').click(); cy.get('#school_name').type('Browser Test School'); cy.get('#currency').type('PKR'); cy.get('#timezone').type('UTC'); cy.contains('button','Save settings').click(); cy.contains('School settings saved.').should('be.visible'); cy.reload(); cy.contains('Browser Test School').should('be.visible');
    });
    it('owner revokes an unused invitation on mobile', () => {
        cy.viewport(390,844); login('owner'); cy.contains('button','Menu').click(); cy.contains('nav button','People & access').click(); cy.contains('button','Invite person').click(); cy.get('#invite-name').type('Cancelled Teacher'); cy.get('#invite-email').type('cancelled@example.test'); cy.contains('button','Create private invitation').click();
        cy.get('#invitation-link').invoke('val').then(url => {
            cy.get('dialog[open]').within(() => cy.get('button[aria-label]').click()); cy.contains('button','Manage invitations').click(); cy.contains('button','Skip guide').click();
            cy.contains('[data-cy=invitation]','Cancelled Teacher').within(() => cy.contains('button','Revoke invitation').click());
            cy.contains('[data-cy=invitation]','Cancelled Teacher').should('contain','Expired or revoked'); cy.document().then(doc => expect(doc.documentElement.scrollWidth).to.be.at.most(390)); cy.screenshot('invitations-mobile', {capture:'viewport'});
            cy.contains('button','Sign out').click(); cy.request({url,failOnStatusCode:false}).its('status').should('eq',404);
        });
    });
    it('rapid navigation keeps the newest page records', () => {
        login('owner');
        cy.intercept('GET','/portal/records/subjects*', request => request.continue(response => response.setDelay(1500))).as('slowSubjects');
        cy.get('[data-cy=nav-subjects]').click(); cy.get('[data-cy=nav-classes]').click(); cy.contains('button','Skip guide').click(); cy.wait('@slowSubjects'); cy.contains('h1','Classes & sections').should('be.visible'); cy.contains('[data-cy=record]','Grade 5 A').should('be.visible'); cy.contains('[data-cy=record]','English').should('not.exist');
    });
});
