<?php

declare(strict_types=1);

namespace OCA\Budget\Command;

use OCA\Budget\Service\Ocr\NextcloudOcrBackend;
use OCA\Budget\Service\OcrSettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read and change the receipt-scanning provider from the command line.
 *
 * The admin panel is the normal way in; this exists for headless and scripted
 * installs, and to keep people away from `occ config:app:set budget ocr_*`,
 * which looks equivalent and is not: it skips the provider and URL validation,
 * and it would write an API key to the database in PLAINTEXT, because the
 * encryption lives in OcrSettingsService rather than in the config layer.
 * Everything here goes through that same service, so the CLI and the panel
 * cannot drift apart.
 */
class OcrConfig extends Command {
    public function __construct(
        private OcrSettingsService $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('budget:ocr')
            ->setDescription('Show or change the receipt-scanning (OCR) provider')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'show (default) or set',
                'show'
            )
            ->addOption('provider', null, InputOption::VALUE_REQUIRED,
                'none, nextcloud, custom or relay')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED,
                'Base URL of an OpenAI-compatible server, e.g. http://192.168.1.10:11434/v1')
            ->addOption('model', null, InputOption::VALUE_REQUIRED,
                'Vision model to ask for, e.g. qwen2.5vl:7b')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED,
                'API or license key. Stored encrypted. Use --api-key="" to remove it. '
                . 'Prefer --api-key-stdin so the key stays out of your shell history')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE,
                'Read the key from standard input instead of the command line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = (string)$input->getArgument('action');

        if ($action === 'show') {
            return $this->show($output);
        }

        if ($action !== 'set') {
            $output->writeln('<error>Unknown action "' . $action . '". Use show or set.</error>');

            return 1;
        }

        return $this->set($input, $output);
    }

    private function show(OutputInterface $output): int {
        $s = $this->settings->getSettings();

        $output->writeln('Receipt scanning');
        $output->writeln('  provider:  ' . $s['provider']
            . ($s['provider'] === OcrSettingsService::PROVIDER_NONE ? ' (off — no images are sent anywhere)' : ''));
        $output->writeln('  endpoint:  ' . ($s['endpoint'] !== '' ? $s['endpoint'] : '-'));
        $output->writeln('  model:     ' . ($s['model'] !== '' ? $s['model'] : '-'));
        $output->writeln('  api key:   ' . ($s['apiKeySet'] ? 'set (encrypted, not shown)' : 'not set'));
        $output->writeln('');
        $output->writeln('  usable:    ' . ($s['configured']
            ? '<info>yes</info>'
            : '<comment>no</comment> — clients see receipt scanning as unavailable'));

        if ($s['provider'] === OcrSettingsService::PROVIDER_NEXTCLOUD && !$s['nextcloudAiAvailable']) {
            $output->writeln('             this server has no ' . NextcloudOcrBackend::TASK_TYPE
                . ' provider installed');
        }
        if ($s['provider'] === OcrSettingsService::PROVIDER_CUSTOM && $s['configured']) {
            $output->writeln('');
            $output->writeln('  <comment>Note</comment> a LAN or localhost endpoint also needs '
                . "'allow_local_remote_servers' => true in config.php,");
            $output->writeln('       or Nextcloud refuses to call it.');
        }

        return 0;
    }

    private function set(InputInterface $input, OutputInterface $output): int {
        $values = [];

        foreach (['provider' => 'provider', 'endpoint' => 'endpoint', 'model' => 'model'] as $option => $key) {
            $given = $input->getOption($option);
            if ($given !== null) {
                $values[$key] = (string)$given;
            }
        }

        if ($input->getOption('api-key-stdin')) {
            $piped = stream_get_contents(STDIN);
            $values['apiKey'] = trim($piped === false ? '' : $piped);
        } elseif ($input->getOption('api-key') !== null) {
            $values['apiKey'] = (string)$input->getOption('api-key');
        }

        if ($values === []) {
            $output->writeln('<error>Nothing to set. Pass at least one of '
                . '--provider, --endpoint, --model, --api-key or --api-key-stdin.</error>');

            return 1;
        }

        try {
            // Same validation, same encryption, same partial-update semantics
            // as the admin panel — a rejected value changes nothing at all.
            $this->settings->update($values);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        $output->writeln('<info>Updated.</info>');
        $output->writeln('');

        return $this->show($output);
    }
}
