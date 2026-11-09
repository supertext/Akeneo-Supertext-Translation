import React, {useEffect, useState} from 'react';
import styled from 'styled-components';
import {Breadcrumb, Button, Checkbox, Field, Helper, Link, Locale, SectionTitle, SelectInput, Table, TextInput, getColor} from 'akeneo-design-system';
import {PageContent, PageHeader, PimView, useRoute} from '@akeneo-pim-community/shared';
import {ApiError, KeyCheck, LanguageSetting, Links, SettingsData, TargetLanguage, fetchSettings, saveSettings, testConnection} from '../api';

const __ = require('oro/translator');

const Form = styled.div`
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 760px;
  padding-bottom: 40px;
`;

const Note = styled.p`
  color: ${getColor('grey', 120)};
  margin: 0;
`;

const environments = ['live', 'staging', 'testing', 'custom'] as const;
const politeness = ['', 'more', 'less'] as const;

/** The text with "supertext.com → Integrations → API" and "supertext.com" as links (new tab), in every language. */
const linkify = (text: string, links: Links) =>
  text.split(/(supertext\.com → Integrations → API|supertext\.com)/).map((part, index) =>
    part === 'supertext.com → Integrations → API' ? (
      <Link key={index} href={links.api_key} target="_blank" rel="noopener">
        {part}
      </Link>
    ) : part === 'supertext.com' ? (
      <Link key={index} href={links.signup} target="_blank" rel="noopener">
        {part}
      </Link>
    ) : (
      part
    )
  );

const formatTime = (value: string): string => {
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

/** The latest automatic API key check, with its time (keycheck.* in the four languages). */
const KeyCheckMessage = ({check, links}: {check: KeyCheck; links: Links}) => (
  <div className="supertext-key-check">
    <Helper level={check.status === 'ok' ? 'success' : check.status === 'invalid' || check.status === 'missing' ? 'error' : 'warning'}>
      {linkify(__(`supertext_translation.keycheck.${check.status}`, {status: String(check.http_status ?? '?')}), links)}{' '}
      {__('supertext_translation.keycheck.checked_at', {time: formatTime(check.checked_at)})}
    </Helper>
  </div>
);

const targetLabel = (target: TargetLanguage) => `${target.name} (${target.code})`;

const SettingsPage = () => {
  const systemRoute = useRoute('pim_system_index');
  const [settings, setSettings] = useState<SettingsData | null>(null);
  const [apiKey, setApiKey] = useState<string>('');
  const [environment, setEnvironment] = useState<string>('live');
  const [apiUrl, setApiUrl] = useState<string>('');
  const [timeout, setTimeoutValue] = useState<string>('180');
  const [languages, setLanguages] = useState<LanguageSetting[]>([]);
  const [versionReports, setVersionReports] = useState<boolean>(true);
  const [keyCheck, setKeyCheck] = useState<KeyCheck | null>(null);
  const [message, setMessage] = useState<{level: 'success' | 'error' | 'info'; text: string} | null>(null);
  const [busy, setBusy] = useState<boolean>(false);

  const load = (data: SettingsData) => {
    setSettings(data);
    setApiKey('');
    setEnvironment(data.environment);
    setApiUrl(data.api_url);
    setTimeoutValue(String(data.timeout));
    setLanguages(data.languages);
    setVersionReports(data.version_reports);
    setKeyCheck(data.key_check);
  };

  useEffect(() => {
    fetchSettings()
      .then(load)
      .catch((e: ApiError) => setMessage({level: 'error', text: e.message}));
  }, []);

  const input = () => ({
    api_key: apiKey,
    environment,
    api_url: apiUrl,
    timeout: Number(timeout),
    version_reports: versionReports,
    languages: Object.fromEntries(languages.map(language => [language.locale, {code: language.code, politeness: language.politeness, review: language.review}])),
  });

  const save = () => {
    setBusy(true);
    saveSettings(input())
      .then(data => {
        load(data);
        setMessage({level: 'success', text: __('supertext_translation.settings.saved')});
      })
      .catch((e: ApiError) => setMessage({level: 'error', text: e.message}))
      .finally(() => setBusy(false));
  };

  const test = () => {
    setBusy(true);
    testConnection(input())
      .then(result => {
        setMessage(null);
        result.key_check !== null ? setKeyCheck(result.key_check) : setMessage({level: 'error', text: result.message});
      })
      .catch((e: ApiError) => setMessage({level: 'error', text: e.message}))
      .finally(() => setBusy(false));
  };

  const updateLanguage = (locale: string, change: Partial<LanguageSetting>) =>
    setLanguages(current => current.map(language => (language.locale === locale ? {...language, ...change} : language)));

  const keyFromEnvironment = settings?.api_key_source === 'environment';

  /** Whether Supertext offers formal/informal for a target (a code it doesn't list: assume yes, the API decides). */
  const supportsPoliteness = (code: string): boolean => settings?.targets.find(target => target.code === code)?.politeness ?? true;

  return (
    <>
      <PageHeader>
        <PageHeader.Breadcrumb>
          <Breadcrumb>
            <Breadcrumb.Step href={`#${systemRoute}`}>{__('pim_menu.tab.system')}</Breadcrumb.Step>
            <Breadcrumb.Step>Supertext</Breadcrumb.Step>
          </Breadcrumb>
        </PageHeader.Breadcrumb>
        <PageHeader.UserActions>
          <PimView viewName="pim-menu-user-navigation" className="AknTitleContainer-userMenuContainer AknTitleContainer-userMenu" />
        </PageHeader.UserActions>
        <PageHeader.Actions>
          <Button level="tertiary" ghost onClick={test} disabled={busy || settings === null} className="supertext-test-button">
            {__('supertext_translation.settings.test')}
          </Button>
          <Button level="primary" onClick={save} disabled={busy || settings === null} className="supertext-save-button">
            {__('pim_common.save')}
          </Button>
        </PageHeader.Actions>
        <PageHeader.Title>{__('supertext_translation.settings.title')}</PageHeader.Title>
      </PageHeader>
      <PageContent>
        <Form className="supertext-settings">
          {message !== null && <Helper level={message.level}>{message.text}</Helper>}
          {settings !== null && keyCheck !== null && <KeyCheckMessage check={keyCheck} links={settings.links} />}
          {settings !== null && (
            <>
              <SectionTitle>
                <SectionTitle.Title>{__('supertext_translation.settings.connection')}</SectionTitle.Title>
              </SectionTitle>
              <Field label={__('supertext_translation.settings.api_key')}>
                <TextInput
                  type="password"
                  value={apiKey}
                  autoComplete="new-password"
                  readOnly={keyFromEnvironment}
                  placeholder={
                    keyFromEnvironment
                      ? __('supertext_translation.settings.key_from_environment')
                      : settings.api_key_hint !== ''
                      ? __('supertext_translation.settings.key_saved', {hint: settings.api_key_hint})
                      : ''
                  }
                  onChange={(value: string) => setApiKey(value)}
                />
                <Helper level="info">
                  {__('supertext_translation.no_account')}{' '}
                  <Link href={settings.links.signup} target="_blank">
                    {__('supertext_translation.create_account')}
                  </Link>
                  {'. '}
                  {__('supertext_translation.generate_key')}{' '}
                  <Link href={settings.links.api_key} target="_blank">
                    {__('supertext_translation.key_page')}
                  </Link>{' '}
                  {__('supertext_translation.admin_role')}{' '}
                  {keyFromEnvironment ? __('supertext_translation.settings.key_environment_help') : __('supertext_translation.settings.key_help')}
                </Helper>
              </Field>
              <Field label={__('supertext_translation.settings.environment')}>
                <SelectInput
                  value={environment}
                  onChange={(value: string) => setEnvironment(value)}
                  clearable={false}
                  readOnly={settings.api_url_from_environment}
                  emptyResultLabel={__('pim_common.no_result')}
                  openLabel={__('pim_common.open')}
                >
                  {environments.map(value => (
                    <SelectInput.Option key={value} value={value}>
                      {__(`supertext_translation.settings.environments.${value}`)}
                    </SelectInput.Option>
                  ))}
                </SelectInput>
                {settings.api_url_from_environment && (
                  <Helper level="info">{__('supertext_translation.settings.url_from_environment', {url: settings.effective_url})}</Helper>
                )}
              </Field>
              {environment === 'custom' && !settings.api_url_from_environment && (
                <Field label={__('supertext_translation.settings.api_url')}>
                  <TextInput value={apiUrl} placeholder="https://api.supertext.com/v1/" onChange={(value: string) => setApiUrl(value)} />
                </Field>
              )}
              <Field label={__('supertext_translation.settings.timeout')}>
                <TextInput value={timeout} onChange={(value: string) => setTimeoutValue(value.replace(/[^0-9]/g, ''))} />
                <Helper level="info">{__('supertext_translation.settings.timeout_help')}</Helper>
              </Field>
              <div className="supertext-version-reports">
                <Checkbox
                  checked={settings.version_reports_from_environment ? settings.version_reports_enabled : versionReports}
                  readOnly={settings.version_reports_from_environment}
                  onChange={(value: boolean) => setVersionReports(value)}
                >
                  {__('supertext_translation.settings.version_reports')}
                </Checkbox>
                <Helper level="info">
                  {__('supertext_translation.settings.version_reports_help')}
                  {settings.version_reports_from_environment && ` ${__('supertext_translation.settings.version_reports_environment')}`}
                </Helper>
              </div>

              <SectionTitle>
                <SectionTitle.Title>{__('supertext_translation.settings.languages')}</SectionTitle.Title>
              </SectionTitle>
              <Note>{__('supertext_translation.settings.languages_help')}</Note>
              <Table className="supertext-languages">
                <Table.Header>
                  <Table.HeaderCell>{__('supertext_translation.settings.locale')}</Table.HeaderCell>
                  <Table.HeaderCell>{__('supertext_translation.settings.code')}</Table.HeaderCell>
                  <Table.HeaderCell>{__('supertext_translation.settings.politeness')}</Table.HeaderCell>
                  <Table.HeaderCell>{__('supertext_translation.settings.review')}</Table.HeaderCell>
                </Table.Header>
                <Table.Body>
                  {languages.map(language => (
                    <Table.Row key={language.locale}>
                      <Table.Cell>
                        <Locale code={language.locale} languageLabel={language.label} />
                      </Table.Cell>
                      <Table.Cell>
                        <SelectInput
                          value={language.code !== '' ? language.code : null}
                          onChange={(value: string | null) =>
                            updateLanguage(language.locale, {
                              code: value ?? '',
                              politeness: supportsPoliteness(value ?? '') ? language.politeness : '',
                            })
                          }
                          clearable={false}
                          placeholder={__('supertext_translation.settings.choose_code')}
                          emptyResultLabel={__('pim_common.no_result')}
                          openLabel={__('pim_common.open')}
                          invalid={language.code === ''}
                        >
                          {[
                            ...settings.targets,
                            ...(language.code !== '' && !settings.targets.some(target => target.code === language.code)
                              ? [{code: language.code, name: language.code, politeness: true}]
                              : []),
                          ].map(target => (
                            <SelectInput.Option key={target.code} value={target.code}>
                              {targetLabel(target)}
                            </SelectInput.Option>
                          ))}
                        </SelectInput>
                        {language.code === '' && (
                          <Helper inline level="warning">
                            {__('supertext_translation.settings.not_mapped')}
                          </Helper>
                        )}
                      </Table.Cell>
                      <Table.Cell>
                        {language.code === '' || supportsPoliteness(language.code) ? (
                          <SelectInput
                            value={language.politeness}
                            onChange={(value: string) => updateLanguage(language.locale, {politeness: value as LanguageSetting['politeness']})}
                            readOnly={language.code === ''}
                            clearable={false}
                            emptyResultLabel={__('pim_common.no_result')}
                            openLabel={__('pim_common.open')}
                          >
                            {politeness.map(value => (
                              <SelectInput.Option key={value || 'default'} value={value}>
                                {__(`supertext_translation.settings.politeness_${value || 'default'}`)}
                              </SelectInput.Option>
                            ))}
                          </SelectInput>
                        ) : (
                          <Helper inline level="info">
                            {__('supertext_translation.settings.no_politeness')}
                          </Helper>
                        )}
                      </Table.Cell>
                      <Table.Cell>
                        <Checkbox
                          className="supertext-review"
                          checked={language.review}
                          readOnly={settings.review_url === ''}
                          onChange={(value: boolean) => updateLanguage(language.locale, {review: value})}
                          title={__('supertext_translation.settings.review_label', {language: language.label})}
                        />
                      </Table.Cell>
                    </Table.Row>
                  ))}
                </Table.Body>
              </Table>
              <Note className="supertext-review-help">
                {__('supertext_translation.settings.review_help')}
                {settings.review_url === '' && ` ${__('supertext_translation.settings.review_off')}`}
              </Note>
              <Note className="supertext-version">
                {__('supertext_translation.settings.version')}{' '}
                {settings.release_url !== '' ? (
                  <Link href={settings.release_url} target="_blank">
                    {settings.version}
                  </Link>
                ) : (
                  settings.version || __('supertext_translation.settings.version_unknown')
                )}
              </Note>
            </>
          )}
        </Form>
      </PageContent>
    </>
  );
};

export {SettingsPage};
