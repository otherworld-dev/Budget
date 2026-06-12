<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Mail;

use OCA\Budget\AppInfo\Application;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * The app's only email gateway (digest + scheduled reports). Resolves the
 * recipient and language per user, renders Nextcloud-themed templates, and
 * treats every failure as non-fatal: notifications are the primary channel,
 * email is best-effort on instances with working SMTP.
 */
class BudgetMailService {

    public function __construct(
        private IMailer $mailer,
        private IUserManager $userManager,
        private IConfig $config,
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send a sectioned HTML email. $sections is a list of
     * ['heading' => ?string, 'lines' => string[]] blocks.
     *
     * @param array{name: string, content: string}|null $attachment
     * @return bool true when the mail was handed to the mailer successfully
     */
    public function send(string $userId, string $subject, string $heading, array $sections, ?array $attachment = null): bool {
        $user = $this->userManager->get($userId);
        $address = $user?->getEMailAddress();
        if ($user === null || $address === null || $address === '') {
            $this->logger->debug("Budget mail skipped for {$userId}: no email address", ['app' => Application::APP_ID]);
            return false;
        }

        try {
            $l = $this->l10nFactory->get(Application::APP_ID, $this->getUserLanguage($userId));

            $template = $this->mailer->createEMailTemplate('budget.' . preg_replace('/[^a-zA-Z]/', '', $heading));
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading($heading);

            foreach ($sections as $section) {
                if (!empty($section['heading'])) {
                    $template->addBodyText('');
                    $template->addBodyText('**' . $section['heading'] . '**', $section['heading']);
                }
                foreach ($section['lines'] as $line) {
                    $template->addBodyText($line);
                }
            }

            $template->addBodyButton(
                $l->t('Open Budget'),
                $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index')
            );
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$address => $user->getDisplayName()]);
            $message->setFrom([\OCP\Util::getDefaultEmailAddress('no-reply') => 'Nextcloud Budget']);
            $message->useTemplate($template);

            if ($attachment !== null) {
                $message->attach(
                    $this->mailer->createAttachment($attachment['content'], $attachment['name'], 'application/pdf')
                );
            }

            $failed = $this->mailer->send($message);
            if (!empty($failed)) {
                $this->logger->warning('Budget mail rejected for: ' . implode(', ', $failed), ['app' => Application::APP_ID]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            // SMTP unconfigured or transient failure — never fatal
            $this->logger->warning("Budget mail to {$userId} failed: " . $e->getMessage(), ['app' => Application::APP_ID]);
            return false;
        }
    }

    public function getUserLanguage(string $userId): string {
        try {
            $lang = $this->config->getUserValue($userId, 'core', 'lang', '');
            return $lang !== '' ? $lang : ($this->l10nFactory->findLanguage(Application::APP_ID) ?: 'en');
        } catch (\Exception $e) {
            return 'en';
        }
    }
}
