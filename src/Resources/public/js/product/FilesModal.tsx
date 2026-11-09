/**
 * "Export for translation" and "Import translation" (XLIFF 1.2 or HTML) for a product or product
 * model, opened from the "…" menu of the edit form (files-action.tsx). See TranslationFileGlue.
 */
import React, {useEffect, useState} from 'react';
import styled from 'styled-components';
import {Button, Checkbox, Field, Helper, Locale, Modal, SelectInput, getColor} from 'akeneo-design-system';
import {ApiError, Context, EntityType, ImportedJob, MAX_UPLOAD_BYTES, exportFile, fetchContext, importFile, localize} from '../api';

const __ = require('oro/translator');

const Content = styled.div`
  display: flex;
  flex-direction: column;
  gap: 20px;
  width: 520px;
  max-height: calc(100vh - 260px);
  overflow-y: auto;
  padding-right: 4px;
`;

const Targets = styled.div`
  display: flex;
  flex-direction: column;
  gap: 10px;
`;

const Label = styled.div`
  color: ${getColor('grey', 140)};
  font-size: 13px;
  margin-bottom: 8px;
`;

const Jobs = styled.ul`
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
`;

const Job = styled.li<{$failed: boolean}>`
  color: ${({$failed}) => ($failed ? getColor('red', 100) : getColor('grey', 140))};
`;

type Format = 'xliff' | 'html';

type Props = {
  mode: 'export' | 'import';
  type: EntityType;
  id: string;
  onClose: () => void;
  onImported: () => void;
};

const summary = (job: ImportedJob): string[] => {
  if (job.error !== null) {
    return [localize(job.error)];
  }
  const lines = [__('supertext_translation.files.summary.ok', {count: job.imported})];
  const counts: [string, number][] = [
    ['existing', job.existing],
    ['changed', job.source_changed],
    ['untranslated', job.untranslated],
    ['invalid', job.invalid],
    ['missing', job.missing],
  ];
  counts.filter(([, count]) => count > 0).forEach(([name, count]) => lines.push(__(`supertext_translation.files.summary.${name}`, {count})));
  if (job.problem !== null) {
    lines.push(localize(job.problem));
  }

  return lines;
};

const ExportForm = ({type, id, onClose}: {type: EntityType; id: string; onClose: () => void}) => {
  const [context, setContext] = useState<Context | null>(null);
  const [source, setSource] = useState<string>('');
  const [targets, setTargets] = useState<string[]>([]);
  const [format, setFormat] = useState<Format>('xliff');
  const [busy, setBusy] = useState<boolean>(false);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchContext(type, id, source)
      .then(data => {
        if (cancelled) return;
        setContext(data);
        if (source === '') setSource(data.source);
        setTargets(current => current.filter(code => code !== data.source));
        setError(null);
      })
      .catch((e: ApiError) => !cancelled && setError(e));

    return () => {
      cancelled = true;
    };
  }, [type, id, source]);

  const toggle = (code: string, checked: boolean) =>
    setTargets(current => (checked ? (format === 'html' ? [code] : [...current, code]) : current.filter(target => target !== code)));

  const run = () => {
    setBusy(true);
    setError(null);
    exportFile(type, id, source, targets, format)
      .then(() => onClose())
      .catch((e: ApiError) => setError(e))
      .finally(() => setBusy(false));
  };

  const nothing = context !== null && context.preview.units === 0;
  const htmlTooMany = format === 'html' && targets.length > 1;

  return (
    <>
      <Content>
        {error !== null && <Helper level="error">{error.message}</Helper>}
        {context === null && error === null && <Label>{__('supertext_translation.loading')}</Label>}
        {context !== null && (
          <>
            <Field label={__('supertext_translation.modal.from')}>
              <SelectInput
                value={source}
                onChange={(value: string) => setSource(value)}
                emptyResultLabel={__('pim_common.no_result')}
                openLabel={__('pim_common.open')}
                clearable={false}
                readOnly={busy}
              >
                {context.locales.map(locale => (
                  <SelectInput.Option key={locale.code} value={locale.code}>
                    {locale.label}
                  </SelectInput.Option>
                ))}
              </SelectInput>
            </Field>

            {nothing ? (
              <Helper level="info">{__('supertext_translation.files.nothing')}</Helper>
            ) : (
              <>
                <Field label={__('supertext_translation.files.format')}>
                  <SelectInput
                    value={format}
                    onChange={(value: string) => setFormat(value === 'html' ? 'html' : 'xliff')}
                    emptyResultLabel={__('pim_common.no_result')}
                    openLabel={__('pim_common.open')}
                    clearable={false}
                    readOnly={busy}
                  >
                    <SelectInput.Option value="xliff">{__('supertext_translation.files.xliff')}</SelectInput.Option>
                    <SelectInput.Option value="html">{__('supertext_translation.files.html')}</SelectInput.Option>
                  </SelectInput>
                </Field>
                <div>
                  <Label>{__('supertext_translation.modal.to')}</Label>
                  <Targets className="supertext-targets">
                    {context.locales
                      .filter(locale => locale.code !== source)
                      .map(locale => (
                        <Checkbox
                          key={locale.code}
                          checked={targets.includes(locale.code)}
                          readOnly={busy}
                          onChange={(checked: boolean) => toggle(locale.code, checked)}
                        >
                          <Locale code={locale.code} languageLabel={locale.label} />
                        </Checkbox>
                      ))}
                  </Targets>
                </div>
                {htmlTooMany && <Helper level="warning">{__('supertext_translation.error.file_html_one')}</Helper>}
              </>
            )}
          </>
        )}
      </Content>
      <Modal.BottomButtons>
        <Button level="tertiary" onClick={onClose} disabled={busy}>
          {__('pim_common.cancel')}
        </Button>
        <Button
          level="primary"
          onClick={run}
          disabled={busy || context === null || nothing || targets.length === 0 || htmlTooMany}
          className="supertext-export-button"
        >
          {__('supertext_translation.files.download')}
        </Button>
      </Modal.BottomButtons>
    </>
  );
};

const ImportForm = ({onClose, onImported}: {onClose: () => void; onImported: () => void}) => {
  const [file, setFile] = useState<File | null>(null);
  const [includeChanged, setIncludeChanged] = useState<boolean>(false);
  const [overwrite, setOverwrite] = useState<boolean>(false);
  const [busy, setBusy] = useState<boolean>(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [jobs, setJobs] = useState<ImportedJob[] | null>(null);

  const run = () => {
    if (file === null) return;
    if (file.size > MAX_UPLOAD_BYTES) {
      setError(new ApiError(__('supertext_translation.error.file_too_large')));
      return;
    }
    setBusy(true);
    setError(null);
    importFile(file, includeChanged, overwrite)
      .then(result => setJobs(result))
      .catch((e: ApiError) => setError(e))
      .finally(() => setBusy(false));
  };

  const close = () => (jobs !== null && jobs.some(job => job.imported > 0) ? onImported() : onClose());

  return (
    <>
      <Content>
        {error !== null && <Helper level="error">{error.message}</Helper>}
        {jobs === null ? (
          <>
            <Field label={__('supertext_translation.files.file')}>
              <input
                type="file"
                className="supertext-import-file"
                accept=".xlf,.xliff,.xml,.html,.htm"
                disabled={busy}
                onChange={event => setFile(event.target.files && event.target.files.length > 0 ? event.target.files[0] : null)}
              />
            </Field>
            <Checkbox checked={includeChanged} readOnly={busy} onChange={(checked: boolean) => setIncludeChanged(checked)}>
              {__('supertext_translation.files.include_changed')}
            </Checkbox>
            <Checkbox checked={overwrite} readOnly={busy} onChange={(checked: boolean) => setOverwrite(checked)}>
              {__('supertext_translation.modal.overwrite')}
            </Checkbox>
          </>
        ) : (
          <Jobs className="supertext-import-summary">
            {jobs.map(job => (
              <Job key={job.reference} $failed={job.error !== null || (job.problem !== null && job.imported === 0)}>
                <strong>
                  {job.label !== '' ? job.label : job.reference} → <Locale code={job.locale} />
                </strong>
                {summary(job).map((line, index) => (
                  <div key={index}>{line}</div>
                ))}
              </Job>
            ))}
          </Jobs>
        )}
      </Content>
      <Modal.BottomButtons>
        {jobs === null ? (
          <>
            <Button level="tertiary" onClick={onClose} disabled={busy}>
              {__('pim_common.cancel')}
            </Button>
            <Button level="primary" onClick={run} disabled={busy || file === null} className="supertext-import-button">
              {__('supertext_translation.files.import_action')}
            </Button>
          </>
        ) : (
          <Button level="primary" onClick={close} className="supertext-close-button">
            {__('supertext_translation.modal.done')}
          </Button>
        )}
      </Modal.BottomButtons>
    </>
  );
};

const FilesModal = ({mode, type, id, onClose, onImported}: Props) => (
  <Modal onClose={onClose} closeTitle={__('pim_common.close')} className="supertext-files-modal">
    <Modal.SectionTitle color="brand">Supertext</Modal.SectionTitle>
    <Modal.Title>{__(mode === 'export' ? 'supertext_translation.files.export_action' : 'supertext_translation.files.import_action')}</Modal.Title>
    {mode === 'export' ? <ExportForm type={type} id={id} onClose={onClose} /> : <ImportForm onClose={onClose} onImported={onImported} />}
  </Modal>
);

export {FilesModal};
