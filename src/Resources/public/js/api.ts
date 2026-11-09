const Routing = require('routing');
const __ = require('oro/translator');

type EntityType = 'product' | 'product_model';

type Links = {signup: string; api_key: string};

type TargetPreview = {translate: number; existing: number; unavailable: number};

type Context = {
  configured: boolean;
  links: Links;
  source: string;
  /** Locales without a Supertext language (no mapping under System → Supertext, no exact match, no default). */
  unmapped: string[];
  variant: boolean;
  locales: {code: string; label: string}[];
  preview: {units: number; targets: {[locale: string]: TargetPreview}};
};

/** A server message: English `message`, plus `key` (supertext_translation.error.<key>), `params` and untranslated `detail` for the UI. */
type ServerMessage = {message: string; key?: string; params?: {[name: string]: string | number}; detail?: string};

type TargetResult = ServerMessage & {status: 'translated' | 'nothing' | 'error'; translated: number; existing: number};

type LanguageSetting = {locale: string; label: string; code: string; suggested_code: string; politeness: '' | 'more' | 'less'; review: boolean};

/** A Supertext target language (the SDK's Languages::targetOptions(), name in the user's interface language). */
type TargetLanguage = {code: string; name: string; politeness: boolean};

/** The latest automatic API key check (keycheck.<status> in jsmessages). */
type KeyCheck = {status: 'ok' | 'invalid' | 'missing' | 'unreachable' | 'error'; checked_at: string; http_status: number | null};

type SettingsData = {
  version: string;
  release_url: string;
  api_key_source: '' | 'settings' | 'environment';
  api_key_hint: string;
  environment: 'live' | 'staging' | 'testing' | 'custom';
  api_url: string;
  api_url_from_environment: boolean;
  effective_url: string;
  /** The review service for locales with Translation review ('' = off for every locale). */
  review_url: string;
  timeout: number;
  languages: LanguageSetting[];
  targets: TargetLanguage[];
  version_reports: boolean;
  version_reports_from_environment: boolean;
  version_reports_enabled: boolean;
  ping_url: string;
  key_check: KeyCheck | null;
  links: Links;
};

/** The message in the user's interface language; the English message if the key has no translation. */
const localize = (data: ServerMessage): string => {
  if (!data.key) {
    return data.message;
  }
  const id = `supertext_translation.error.${data.key}`;
  const text = __(id, data.params || {});
  const localized = text === id ? data.message : text;

  return text !== id && data.detail ? `${localized} (${data.detail})` : localized;
};

const localizeAll = (data: ServerMessage & {errors?: ServerMessage[]}): string =>
  Array.isArray(data.errors) && data.errors.length > 0 ? data.errors.map(localize).join(' ') : localize(data);

class ApiError extends Error {
  constructor(message: string, readonly links: Links | null = null) {
    super(message);
  }
}

const request = async <T>(route: string, params: {[key: string]: string}, body?: unknown): Promise<T> => {
  let response: Response;
  try {
    response = await fetch(Routing.generate(route, params), {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', Accept: 'application/json'},
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (e) {
    throw new ApiError(__('supertext_translation.error.network'));
  }

  const data = await response.json().catch(() => null);
  if (!response.ok) {
    throw new ApiError((data && data.message && localizeAll(data)) || __('supertext_translation.error.http', {status: response.status}), (data && data.links) || null);
  }

  return data as T;
};

const fetchContext = (type: EntityType, id: string, from: string) =>
  request<Context>('supertext_translation_rest_context', from ? {type, id, from} : {type, id});

const translate = (type: EntityType, id: string, from: string, to: string[], overwrite: boolean) =>
  request<{results: {[locale: string]: TargetResult}}>('supertext_translation_rest_translate', {type, id}, {from, to, overwrite});

/** One job of an imported translation file (TranslationFileGlue::import()). */
type ImportedJob = {
  reference: string;
  label: string;
  locale: string;
  error: ServerMessage | null;
  imported: number;
  source_changed: number;
  untranslated: number;
  invalid: number;
  missing: number;
  existing: number;
  problem: ServerMessage | null;
};

const failure = async (response: Response): Promise<ApiError> => {
  const data = await response.json().catch(() => null);

  return new ApiError((data && data.message && localizeAll(data)) || __('supertext_translation.error.http', {status: response.status}));
};

/** Downloads the exported XLIFF or HTML file (the browser saves it under the server's file name). */
const exportFile = async (type: EntityType, id: string, from: string, to: string[], format: 'xliff' | 'html'): Promise<void> => {
  let response: Response;
  try {
    response = await fetch(Routing.generate('supertext_translation_rest_export', {type, id}), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'},
      body: JSON.stringify({from, to, format}),
    });
  } catch (e) {
    throw new ApiError(__('supertext_translation.error.network'));
  }
  if (!response.ok) {
    throw await failure(response);
  }

  const disposition = response.headers.get('Content-Disposition') || '';
  const match = /filename="?([^";]+)"?/.exec(disposition);
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = match ? match[1] : `akeneo-${id}.${format === 'html' ? 'html' : 'xlf'}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

/** Uploads a translated file (at most 10 MB) and returns the summary per job. */
const importFile = async (file: File, includeChanged: boolean, overwrite: boolean): Promise<ImportedJob[]> => {
  const body = new FormData();
  body.append('file', file);
  body.append('include_changed', includeChanged ? '1' : '0');
  body.append('overwrite', overwrite ? '1' : '0');
  let response: Response;
  try {
    response = await fetch(Routing.generate('supertext_translation_rest_import'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'},
      body,
    });
  } catch (e) {
    throw new ApiError(__('supertext_translation.error.network'));
  }
  if (!response.ok) {
    throw await failure(response);
  }

  return ((await response.json()) as {jobs: ImportedJob[]}).jobs;
};

/** The largest file the import accepts (TranslationFileGlue::MAX_UPLOAD_BYTES). */
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

const fetchSettings = () => request<SettingsData>('supertext_translation_rest_settings_get', {});

const saveSettings = (input: object) => request<SettingsData>('supertext_translation_rest_settings_save', {}, input);

const testConnection = (input: object) =>
  request<{ok: boolean; message: string; key_check: KeyCheck | null}>('supertext_translation_rest_settings_test', {}, input);

export {ApiError, localize, fetchContext, translate, exportFile, importFile, MAX_UPLOAD_BYTES, fetchSettings, saveSettings, testConnection};
export type {EntityType, Context, TargetResult, ImportedJob, SettingsData, LanguageSetting, TargetLanguage, KeyCheck, Links, ServerMessage};
