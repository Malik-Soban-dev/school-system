function login(role) {
    cy.visit('/login'); cy.get('#username').type('test.' + role); cy.get('#password').type('Browser-test-12345', {log:false}); cy.get('button[type=submit]').click(); cy.url().should('include','/dashboard'); cy.contains('button','Skip guide').click();
}
function open(key) {
    cy.get(`[data-cy=nav-${key}]`).click();
    cy.contains('button','Skip guide').click();
}
describe('School workflows', () => {
    it('owner creates a subject and replays contextual help', () => {
        login('owner'); open('subjects'); cy.get('[data-cy=add-record]').click(); cy.get('#field-name').type('English'); cy.get('#field-code').type('ENG'); cy.contains('button','Save record').click(); cy.contains('[data-cy=record]','English').should('be.visible');
        cy.contains('button','Page guide').click(); cy.contains('STEP 1 OF 4').should('be.visible'); cy.contains('button','Next tip').click(); cy.contains('Find what you need').should('be.visible'); cy.contains('button','Skip guide').click(); cy.screenshot('owner-desktop');
    });
    it('parent sees only their child on mobile without horizontal scrolling', () => {
        cy.viewport(390,844); login('parent'); cy.contains('button','Menu').click(); open('students'); cy.contains('Alex Student').should('be.visible'); cy.contains('Private Student').should('not.exist'); cy.get('[data-cy=add-record]').should('not.exist');
        cy.document().then(doc => expect(doc.documentElement.scrollWidth).to.be.at.most(390)); cy.screenshot('parent-mobile');
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
});
