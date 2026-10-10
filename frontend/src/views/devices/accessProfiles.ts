/**
 * SNMP access profiles against the new API (Plan 25, `/api/v1/device-access-profiles`, `device_access.manage`).
 *
 * The new profile is SNMP only (v1, v2c or v3) and its secrets are write-only: the API never returns a community or
 * a v3 secret, only whether each is set. So the form never shows a stored secret. When editing, a secret field left
 * blank means "keep the stored one", and only the secrets actually typed are sent. The SNMP version cannot be changed
 * after creation (the API does not accept it), so switching a device between v2c and v3 means a new profile.
 *
 * CLI access for the console (Plan 38) sits on the same profile: protocol, port and username are plain settings, the
 * login and enable passwords are write-only like the SNMP secrets. "Remove CLI login" clears all of it at once.
 *
 * The legacy page stays on the legacy build; the router picks one or the other.
 */
import { ApiError, type ApiClient } from '@/api/client';
import type { components } from '@/api/schema';

export type Profile = components['schemas']['AccessProfileOut'];
export type SnmpVersion = 'v1' | 'v2c' | 'v3';
export const AUTH_PROTOCOLS = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'] as const;
export const PRIV_PROTOCOLS = ['DES', 'AES', 'AES192', 'AES256'] as const;
type AuthProtocol = (typeof AUTH_PROTOCOLS)[number];
type PrivProtocol = (typeof PRIV_PROTOCOLS)[number];
export const CLI_PROTOCOLS = ['ssh', 'telnet'] as const;
type CliProtocol = (typeof CLI_PROTOCOLS)[number];

export interface ProfileForm {
  name: string;
  snmp_version: SnmpVersion;
  timeout_ms: number;
  retries: number;
  community: string;
  /** Optional; used only by device actions (legacy's private community). Never returned by the API. */
  write_community: string;
  v3_username: string;
  v3_auth_protocol: AuthProtocol | '';
  v3_auth_secret: string;
  v3_priv_protocol: PrivProtocol | '';
  v3_priv_secret: string;
  cli_protocol: CliProtocol | '';
  /** Blank means the protocol's default (22 for SSH, 23 for telnet). */
  cli_port: number | null;
  cli_username: string;
  cli_password: string;
  cli_enable_password: string;
  /** Editing only: remove every CLI setting and secret from the profile. */
  clear_cli: boolean;
}

export function emptyForm(): ProfileForm {
  return {
    name: '',
    snmp_version: 'v2c',
    timeout_ms: 2000,
    retries: 1,
    community: '',
    write_community: '',
    v3_username: '',
    v3_auth_protocol: 'SHA',
    v3_auth_secret: '',
    v3_priv_protocol: 'AES',
    v3_priv_secret: '',
    cli_protocol: '',
    cli_port: null,
    cli_username: '',
    cli_password: '',
    cli_enable_password: '',
    clear_cli: false,
  };
}

/** The form for editing a stored profile. Every secret starts blank: the API never returns one. */
export function formFor(profile: Profile): ProfileForm {
  return {
    ...emptyForm(),
    name: profile.name,
    snmp_version: profile.snmp_version as SnmpVersion,
    timeout_ms: profile.timeout_ms,
    retries: profile.retries,
    v3_username: profile.snmp_v3_username ?? '',
    v3_auth_protocol: (profile.snmp_v3_auth_protocol as AuthProtocol | null) ?? 'SHA',
    v3_priv_protocol: (profile.snmp_v3_priv_protocol as PrivProtocol | null) ?? 'AES',
    cli_protocol: profile.cli_protocol ?? '',
    cli_port: profile.cli_port,
    cli_username: profile.cli_username ?? '',
  };
}

/** What is wrong with the form before anything is sent, in the user's terms. Empty means it can be sent. */
export function problems(form: ProfileForm, editing: boolean, before?: Profile): string[] {
  const found: string[] = [];
  if (!form.name.trim()) found.push('Name is required');
  if (form.snmp_version === 'v3') {
    if (!form.v3_username.trim()) found.push('SNMPv3 user name is required');
    if (!form.v3_auth_protocol) found.push('Choose an authentication protocol');
    if (!form.v3_priv_protocol) found.push('Choose a privacy protocol');
    // Blank on edit keeps the stored secret; a typed one must meet the API's minimum.
    for (const [label, value] of [
      ['Authentication', form.v3_auth_secret],
      ['Privacy', form.v3_priv_secret],
    ] as const) {
      if (!editing && !value) found.push(`${label} secret is required`);
      if (value && value.length < 8) found.push(`${label} secret must be at least 8 characters`);
    }
  } else if (!editing && !form.community.trim()) {
    found.push('Community is required');
  }
  found.push(...cliProblems(form, before));
  return found;
}

function cliProblems(form: ProfileForm, before?: Profile): string[] {
  if (form.clear_cli) return [];
  const found: string[] = [];
  if (/\s/.test(form.cli_username.trim())) found.push('CLI username cannot contain spaces');
  if (form.cli_password && !form.cli_username.trim()) found.push('A CLI password needs a CLI username');
  if (form.cli_enable_password && !form.cli_password && !before?.has_cli_password)
    found.push('An enable password needs the CLI login password too');
  if (form.cli_port !== null && (form.cli_port < 1 || form.cli_port > 65535))
    found.push('CLI port must be between 1 and 65535');
  return found;
}

function cliCreate(form: ProfileForm): Partial<CreateBody> {
  const out: Partial<CreateBody> = {};
  if (form.cli_protocol) out.cli_protocol = form.cli_protocol;
  if (form.cli_port !== null) out.cli_port = form.cli_port;
  if (form.cli_username.trim()) out.cli_username = form.cli_username.trim();
  if (form.cli_password) out.cli_password = form.cli_password;
  if (form.cli_enable_password) out.cli_enable_password = form.cli_enable_password;
  return out;
}

export type CreateBody = components['schemas']['ProfileCreate'];
export type UpdateBody = components['schemas']['ProfileUpdate'];

export function createBody(form: ProfileForm): CreateBody {
  const base = {
    name: form.name.trim(),
    snmp_version: form.snmp_version,
    timeout_ms: form.timeout_ms,
    retries: form.retries,
  };
  if (form.snmp_version !== 'v3') {
    return form.write_community
      ? { ...base, ...cliCreate(form), snmp_community: form.community, snmp_write_community: form.write_community }
      : { ...base, ...cliCreate(form), snmp_community: form.community };
  }
  return {
    ...base,
    ...cliCreate(form),
    snmp_v3_username: form.v3_username.trim(),
    snmp_v3_auth_protocol: form.v3_auth_protocol || null,
    snmp_v3_auth_secret: form.v3_auth_secret,
    snmp_v3_priv_protocol: form.v3_priv_protocol || null,
    snmp_v3_priv_secret: form.v3_priv_secret,
  };
}

/** Only what changed, and only the secrets actually typed: a blank secret is left out, so the stored one is kept. */
export function updateBody(form: ProfileForm, before: Profile): UpdateBody {
  const body: UpdateBody = {};
  if (form.name.trim() !== before.name) body.name = form.name.trim();
  if (form.timeout_ms !== before.timeout_ms) body.timeout_ms = form.timeout_ms;
  if (form.retries !== before.retries) body.retries = form.retries;
  if (before.snmp_version === 'v3') {
    if (form.v3_username.trim() !== (before.snmp_v3_username ?? '')) body.snmp_v3_username = form.v3_username.trim();
    if (form.v3_auth_protocol && form.v3_auth_protocol !== before.snmp_v3_auth_protocol)
      body.snmp_v3_auth_protocol = form.v3_auth_protocol;
    if (form.v3_priv_protocol && form.v3_priv_protocol !== before.snmp_v3_priv_protocol)
      body.snmp_v3_priv_protocol = form.v3_priv_protocol;
    if (form.v3_auth_secret) body.snmp_v3_auth_secret = form.v3_auth_secret;
    if (form.v3_priv_secret) body.snmp_v3_priv_secret = form.v3_priv_secret;
  } else {
    if (form.community) body.snmp_community = form.community;
    if (form.write_community) body.snmp_write_community = form.write_community;
  }
  if (form.clear_cli) {
    if (before.cli_username || before.cli_protocol || before.cli_port !== null || before.has_cli_password)
      body.clear_cli = true;
    return body;
  }
  if (form.cli_protocol && form.cli_protocol !== before.cli_protocol) body.cli_protocol = form.cli_protocol;
  if (form.cli_port !== null && form.cli_port !== before.cli_port) body.cli_port = form.cli_port;
  if (form.cli_username.trim() && form.cli_username.trim() !== (before.cli_username ?? ''))
    body.cli_username = form.cli_username.trim();
  if (form.cli_password) body.cli_password = form.cli_password;
  if (form.cli_enable_password) body.cli_enable_password = form.cli_enable_password;
  return body;
}

export function profilesApi(client: ApiClient) {
  const path = (id: string) => ({ params: { path: { profile_id: id } } });
  return {
    async list(): Promise<Profile[]> {
      const { data } = await client.GET('/api/v1/device-access-profiles');
      return data ?? [];
    },
    async create(form: ProfileForm): Promise<void> {
      await client.POST('/api/v1/device-access-profiles', { body: createBody(form) });
    },
    /** Returns false when nothing changed, so nothing was sent. */
    async update(before: Profile, form: ProfileForm): Promise<boolean> {
      const body = updateBody(form, before);
      if (!Object.keys(body).length) return false;
      await client.PATCH('/api/v1/device-access-profiles/{profile_id}', { ...path(before.id), body });
      return true;
    },
    async remove(id: string): Promise<void> {
      await client.DELETE('/api/v1/device-access-profiles/{profile_id}', path(id));
    },
  };
}

/** The API's own reason (a duplicate name, a profile still in use, encryption not configured), or a generic one. */
export function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Please try again.';
}
