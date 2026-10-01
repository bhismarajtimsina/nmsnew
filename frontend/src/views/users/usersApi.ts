/**
 * Users against the new API (Plan 25): `/api/v1/users` (`users.view` to read, `users.manage` to change) and
 * `/api/v1/roles` (`roles.view`).
 *
 * Differences from legacy the page has to respect:
 * - there is no delete: an account is disabled (`is_active: false`), which keeps its audit trail;
 * - a user's role comes back by name, so the role list (with ids) is needed to edit it;
 * - a password the server generates is returned once, on creation or reset, and never again;
 * - the server refuses granting a role more powerful than the actor's own, demoting or disabling the last Super Admin,
 *   and disabling yourself. Its reasons are shown as they come.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

export type User = components['schemas']['UserItem'];
export type Role = components['schemas']['RoleItem'];

export interface UserForm {
  username: string;
  display_name: string;
  email: string;
  role_id: string;
  is_active: boolean;
}

export function emptyUserForm(): UserForm {
  return { username: '', display_name: '', email: '', role_id: '', is_active: true };
}

export function userFormFor(user: User, roles: Role[]): UserForm {
  return {
    username: user.username,
    display_name: user.display_name,
    email: user.email ?? '',
    role_id: roles.find((r) => r.name === user.role)?.id ?? '',
    is_active: user.is_active,
  };
}

/** Mirrors the API's own username rule, so a bad name is caught before sending. */
export const USERNAME_PATTERN = /^[A-Za-z0-9._@-]+$/;

export function userProblems(form: UserForm, editing: boolean): string[] {
  const found: string[] = [];
  if (!editing) {
    if (!form.username.trim()) found.push('Login is required');
    else if (!USERNAME_PATTERN.test(form.username.trim())) found.push('Login may only use letters, digits and . _ @ -');
  }
  if (!form.display_name.trim()) found.push('Name is required');
  if (!form.role_id) found.push('Choose a role');
  return found;
}

export type UpdateBody = components['schemas']['UserUpdate'];

/** Only what changed. The login cannot be changed. */
export function userUpdateBody(form: UserForm, before: User, roles: Role[]): UpdateBody {
  const body: UpdateBody = {};
  if (form.display_name.trim() !== before.display_name) body.display_name = form.display_name.trim();
  if ((form.email.trim() || null) !== before.email) body.email = form.email.trim() || null;
  const beforeRole = roles.find((r) => r.name === before.role)?.id ?? '';
  if (form.role_id && form.role_id !== beforeRole) body.role_id = form.role_id;
  if (form.is_active !== before.is_active) body.is_active = form.is_active;
  return body;
}

export function usersApi(client: ApiClient) {
  const path = (id: string) => ({ params: { path: { user_id: id } } });
  return {
    async list(): Promise<User[]> {
      const { data } = await client.GET('/api/v1/users');
      return data ?? [];
    },
    /** Roles a user can be given. Empty when the viewer lacks `roles.view`; the form then cannot pick one. */
    async roles(): Promise<Role[]> {
      try {
        const { data } = await client.GET('/api/v1/access/roles');
        return data ?? [];
      } catch (error) {
        if (error instanceof ApiError && error.status === 403) return [];
        throw error;
      }
    },
    /** Returns the generated password, shown once, or null. */
    async create(form: UserForm): Promise<string | null> {
      const { data } = await client.POST('/api/v1/users', {
        body: {
          username: form.username.trim(),
          display_name: form.display_name.trim(),
          email: form.email.trim() || null,
          role_id: form.role_id,
        },
      });
      return data?.generated_password ?? null;
    },
    /** Returns false when nothing changed, so nothing was sent. */
    async update(before: User, form: UserForm, roles: Role[]): Promise<boolean> {
      const body = userUpdateBody(form, before, roles);
      if (!Object.keys(body).length) return false;
      await client.PATCH('/api/v1/users/{user_id}', { ...path(before.id), body });
      return true;
    },
    /** Generates a new password (returned once) and signs the user out everywhere. */
    async resetPassword(id: string): Promise<string | null> {
      const { data } = await client.POST('/api/v1/users/{user_id}/password', { ...path(id), body: {} });
      return data?.generated_password ?? null;
    },
    async resetTwoFactor(id: string): Promise<void> {
      await client.POST('/api/v1/users/{user_id}/2fa/reset', path(id));
    },
  };
}

/** The API's own reason, including the password-rule problems it reports as a list. */
export function userErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return 'Please try again.';
  const detail = error.detail as { password_problems?: string[] } | null;
  if (detail && typeof detail === 'object' && Array.isArray(detail.password_problems)) {
    return `The password does not meet the rules: ${detail.password_problems.join(', ')}`;
  }
  return error.message;
}
