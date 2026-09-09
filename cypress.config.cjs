const { defineConfig } = require('cypress');
module.exports = defineConfig({
    e2e: {baseUrl:'http://127.0.0.1:8010', specPattern:'tests/browser/**/*.cy.cjs', supportFile:false},
    viewportWidth:1280, viewportHeight:900, video:false, defaultCommandTimeout:10000,
    screenshotsFolder:'.local-tools/cypress-screenshots', downloadsFolder:'.local-tools/cypress-downloads',
});
