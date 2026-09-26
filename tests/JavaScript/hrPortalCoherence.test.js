import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { hrSections } from '../../resources/js/utilities/hrSections.js';
import { employeeAccountLink, requestedEmployeeUuid } from '../../resources/js/utilities/employeeAccount.js';

const all = () => true;

/** ADR-190 — au portail, la rubrique ouvre la page qui détient l'accès à l'hébergeur. */
test('on the portal, professional emails open the portal page filtered on the site', () => {
    const portal = hrSections('/super-admin/sites/A/rh', all).find((section) => section.code === 'hr-professional-emails');
    const site = hrSections('/administration', all).find((section) => section.code === 'hr-professional-emails');

    assert.equal(portal.href, '/super-admin/professional-emails?site=A');
    assert.equal(site.href, '/administration/professional-emails', 'le RH du site garde sa page');
    assert.equal(hrSections('/super-admin/sites/A/rh', all).find((section) => section.code === 'hr-employees').href, '/super-admin/sites/A/rh/employees');
});

test('the portal HR bar shows no fixed count next to a theme', () => {
    const bar = fs.readFileSync('resources/js/Components/Administration/HrPortalBar.vue', 'utf8');

    assert.doesNotMatch(bar, /group\.sections\.length \}\}/, 'un nombre de liens se lisait comme un nombre de personnes');
});

/** ADR-188 — la fiche employé mène au compte de connexion, au portail comme sur le site. */
test('the employee fiche leads to creating or opening the login account', () => {
    const employee = { uuid: 'e-1', active: true, archived: false, user_account: null };
    const portal = { site: { code: 'A' } };

    assert.deepEqual(employeeAccountLink(employee, all, portal), { kind: 'create', label: 'Créer son compte', href: '/super-admin/workspaces/users?site=A&employe=e-1' });
    assert.equal(employeeAccountLink(employee, all).href, '/administration/users?employe=e-1');
    assert.equal(employeeAccountLink(employee, (ability) => ability !== 'users.create'), null, 'sans le droit, rien n’est proposé');
    assert.equal(employeeAccountLink({ ...employee, active: false }, all), null, 'une fiche inactive ne reçoit pas de nouveau compte');
    assert.equal(employeeAccountLink({ ...employee, archived: true }, all), null);

    const linked = { ...employee, user_account: { uuid: 'u-1', name: 'Rasoa Hery', active: true } };
    assert.equal(employeeAccountLink(linked, all, portal).href, '/super-admin/workspaces/users?site=A&search=Rasoa+Hery');
    assert.equal(employeeAccountLink(linked, all).href, '/administration/users?q=Rasoa+Hery');
    assert.equal(requestedEmployeeUuid('/super-admin/workspaces/users?site=A&employe=e-1'), 'e-1');
});

test('both user screens open the wizard on the employee named in the address', () => {
    for (const file of ['resources/js/Pages/SuperAdmin/Users/Index.vue', 'resources/js/Pages/Administration/Users/Index.vue']) {
        const source = fs.readFileSync(file, 'utf8');
        assert.match(source, /requestedEmployeeUuid\(page\.url\)/, file);
        assert.match(source, /form\.account_kind = 'STAFF';\n\s+form\.employee_uuid = employee\.uuid;\n\s+onEmployeePick\(employee\);/, file);
    }
});

/** Vue n'expose au gabarit qu'une liste de globales : `window` et `URLSearchParams` n'en font pas partie. */
test('no Vue template calls a browser global Vue does not expose', () => {
    const files = fs.readdirSync('resources/js', { recursive: true }).filter((file) => file.endsWith('.vue'));
    const offenders = files.filter((file) => {
        const source = fs.readFileSync(`resources/js/${file}`, 'utf8');
        const template = source.slice(source.indexOf('<template>'));

        return source.includes('<template>') && /(?<![\w.$])(window\.|new URLSearchParams|localStorage\.|navigator\.)/.test(template);
    });

    assert.deepEqual(offenders, [], 'à déplacer dans le script');
});
