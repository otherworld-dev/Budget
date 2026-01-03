module.exports = {
    env: {
        browser: true,
        es2021: true
    },
    extends: [
        'eslint:recommended'
    ],
    parserOptions: {
        ecmaVersion: 12,
        sourceType: 'module'
    },
    globals: {
        OC: 'readonly',
        Chart: 'readonly',
        budgetApp: 'writable'
    },
    rules: {
        'no-unused-vars': 'warn',
        'no-console': 'off',
        'no-debugger': 'error'
    }
};