<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Command;

use Supertext\AkeneoTranslationBundle\Reporting\Reporting;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Translation\EntityTranslator;
use Supertext\PluginHealth\KeyCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/console supertext:check: the installed version, the automatic API key check (cost-free),
 * the language mapping, and a `check` version report to Supertext (unless reports are off).
 */
final class CheckCommand extends Command
{
    protected static $defaultName = 'supertext:check';

    public function __construct(private readonly Settings $settings, private readonly Reporting $reporting, private readonly EntityTranslator $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Checks the Supertext API key, the language mapping and sends a version report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Supertext Translation for Akeneo PIM %s', Settings::version() !== '' ? Settings::version() : '(version unknown)'));

        $siteUrl = Settings::siteUrl();
        $result  = $this->reporting->check($siteUrl);
        $check   = $result?->check;

        if ($check === null) {
            $output->writeln('<error>The API key could not be checked.</error>');

            return Command::FAILURE;
        }

        $message = Reporting::checkMessage($check);
        $output->writeln($check->status === KeyCheck::OK ? $message : "<error>$message</error>");

        if (\in_array($check->status, [KeyCheck::INVALID, KeyCheck::MISSING], true)) {
            $output->writeln('No Supertext account yet? Create one at ' . Settings::SIGNUP_URL);
            $output->writeln('Generate your API key at supertext.com → Integrations → API (requires the Admin role): ' . Settings::API_KEY_URL);
            $output->writeln('Then enter it under System → Supertext or set SUPERTEXT_API_KEY.');
        }

        if ($check->status === KeyCheck::OK) {
            $output->writeln(sprintf('Supertext API: %s, key from %s.', $this->settings->baseUrl(), $this->settings->apiKeySource() === 'environment' ? 'SUPERTEXT_API_KEY' : 'System → Supertext'));
        }

        $languages = [];

        foreach ($this->translator->activatedLocales() as $locale) {
            $languages[] = $locale . ' → ' . ($this->settings->languageCodeOrNull($locale) ?? 'not mapped');
        }

        $output->writeln('Languages: ' . implode(', ', $languages));

        if (\in_array(true, array_map(fn (string $locale): bool => $this->settings->languageCodeOrNull($locale) === null, $this->translator->activatedLocales()), true)) {
            $output->writeln('<comment>Choose a Supertext language for the locales without one under System → Supertext (Languages).</comment>');
        }

        $output->writeln(match (true) {
            !$this->settings->versionReportsEnabled() => 'Version reports to Supertext: off.',
            $siteUrl === null                         => 'Version reports to Supertext: not sent (set AKENEO_PIM_URL to the PIM\'s address).',
            $result->sent                             => sprintf('Version report sent to Supertext (ping address %s).', Reporting::pingUrl((string) $siteUrl)),
            default                                   => 'Version report to Supertext: not sent (the Supertext Commando Center could not be reached; it is tried again later).',
        });

        return $check->status === KeyCheck::OK ? Command::SUCCESS : Command::FAILURE;
    }
}
