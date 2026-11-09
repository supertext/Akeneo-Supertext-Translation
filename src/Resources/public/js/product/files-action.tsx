/**
 * "Export for translation" and "Import translation" in the "…" menu of the product and product
 * model edit forms (form_extensions/supertext.yml, config.mode). Opens FilesModal; after an
 * import that wrote texts the form is reloaded so the editor sees the new values.
 */
import React from 'react';
import ReactDOM from 'react-dom';
import {ThemeProvider} from 'styled-components';
import {pimTheme} from 'akeneo-design-system';
import {FilesModal} from './FilesModal';
import {EntityType} from '../api';
import BaseView = require('pimui/js/view/base');

const __ = require('oro/translator');
const router = require('pim/router');

type Config = {type: EntityType; mode: 'export' | 'import'};

class FilesAction extends BaseView {
  readonly config: Config;
  private container: HTMLDivElement | null = null;

  constructor(options: {config: Config}) {
    super({...options, ...{className: `AknDropdown-menuLink supertext-${options.config.mode}-action`, tagName: 'button'}});

    this.config = {...this.config, ...options.config};
  }

  public events() {
    return {click: this.open};
  }

  public render(): BaseView {
    this.$el.text(__(this.config.mode === 'export' ? 'supertext_translation.files.export_action' : 'supertext_translation.files.import_action'));

    return BaseView.prototype.render.apply(this, arguments);
  }

  public remove(): BaseView {
    this.unmount();

    return BaseView.prototype.remove.apply(this, arguments);
  }

  private open() {
    if (this.config.mode === 'export') {
      this.mount();

      return;
    }
    // Unsaved changes would be lost when the form reloads after importing: ask first.
    this.getRoot().trigger('pim_enrich:form:state:confirm', {
      message: __('supertext_translation.unsaved.message'),
      title: __('supertext_translation.unsaved.title'),
      action: () => this.mount(),
    });
  }

  private mount() {
    this.unmount();
    this.container = document.createElement('div');
    document.body.appendChild(this.container);

    ReactDOM.render(
      <ThemeProvider theme={pimTheme}>
        <FilesModal
          mode={this.config.mode}
          type={this.config.type}
          id={String(this.getFormData().meta.id)}
          onClose={() => this.unmount()}
          onImported={() => this.reload()}
        />
      </ThemeProvider>,
      this.container
    );
  }

  private unmount() {
    if (this.container !== null) {
      ReactDOM.unmountComponentAtNode(this.container);
      this.container.remove();
      this.container = null;
    }
  }

  private reload() {
    this.unmount();
    this.getRoot().trigger('pim_enrich:form:entity:post_fetch', this.getFormData());
    router.reloadPage();
  }
}

export = FilesAction;
