---
paths:
  - 'app/{Http,Policies,Data}/**'
---

# Http Policies Data

## Direct user permissions stay disjoint from role permissions
Spatie lets a permission be pinned to a user as well as carried by their role. That duplicate is invisible on the Roles screen and survives the role being revoked, so the invariant is: a user's direct permissions never overlap what their roles grant.

Enforced in three places, keep all three:
- `UserPermissionsRequest` refuses a newly added permission the saved roles already cover (an existing duplicate stays saveable so it can be cleared).
- `UserRequest` refuses the same overlap on create, where the roles and the direct grants are chosen on one form and both move at once. It also requires `users.permissions` before any pin.
- `RoleController::assign` trims direct grants the new roles now cover.

`admin/Permissions.vue` names direct holders so a pinned grant is auditable there.

## Super admin is named, never derived
`Gate::before` gives `Role::SUPER_ADMIN` every ability while its role row may hold no permission at all, so any subset test against the database reports that it grants nothing. Every reach check spells the role name out instead: `UserPolicy::update` (or anyone with `users.update` takes the account over), `UserRequest` and `UserRolesRequest` (or anyone with `roles.assign` hands the role out), and `UserFormData::inherited` (or the form offers to grant what the account already has).

## The user form is four writes, not one
`admin/users/Form.vue` serves creating and editing. Editing posts three separate writes - details (`users.update`), roles (`roles.assign`, the same endpoint the Roles screen posts) and direct grants (`users.permissions`) - plus the password dialog, because each is a different kind of trust. Creating posts the lot at once, since there is nothing to edit piecemeal yet.

The permission matrix is drawn from the roles that are actually saved on edit, and from the live role selection on create via the `role_grants` prop - `UserFormData::inherited()` has nothing to answer about until the account exists.

## Changing your own email is a permission, not a profile field
`ProfileUpdateRequest` validates `email` only when the account holds `profile.email.update`; without it the key is left out of the rules entirely, so `validated()` cannot carry one and `ProfileController::update` leaves the address alone. It is left out rather than `prohibited` because the field is disabled on the form — anything arriving under that name is a stale page, not an attack to reject.

No role grants `profile.email.update` by default (super admin has it only via `Permission::cases()`). The normal door for an email change is the Users screen, behind `users.update`, which also un-verifies the address.

`settings/Profile.vue` reads the same permission through `usePermissions()` and renders the field disabled when it is missing — a disabled input posts nothing, which is what keeps the two halves in step.
