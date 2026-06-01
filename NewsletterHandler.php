<?php

/**
 * @file plugins/generic/newsletter/NewsletterHandler.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NewsletterHandler
 * @brief Handle requests for newsletter subscription.
 */

namespace APP\plugins\generic\newsletter;

use APP\core\Application;
use APP\facades\Repo;
use PKP\core\Core;
use PKP\config\Config;
use PKP\facades\Locale;
use PKP\security\Validation;
use PKP\security\Role;
use PKP\user\User;
use PKP\security\AccessKeyManager;
use PKP\mail\mailables\ValidateEmailContext as ContextMailable;
use Illuminate\Support\Facades\Mail;

class NewsletterHandler extends \PKP\handler\PKPHandler
{
    /** @var \NewsletterPlugin */
    public $_plugin;

    /**
     * Constructor
     */
    public function __construct($plugin)
    {
        parent::__construct();
        $this->_plugin = $plugin;
    }

    /**
     * Handle the subscribe request
     */
    public function subscribe($args, $request)
    {
        if (!$request->isPost()) {
            return $request->redirect(null, 'index');
        }

        // Honeypot: bots fill hidden fields, humans don't
        $honeypot = $request->getUserVar('website');
        if (!empty($honeypot)) {
            return $this->_jsonResponse(['status' => 'error', 'message' => 'Subscription failed. Please try again.']);
        }

        // reCAPTCHA v2 verification (reuses site-wide captcha config)
        $recaptchaToken = $request->getUserVar('g-recaptcha-response');
        $recaptchaSecret = Config::getVar('captcha', 'recaptcha_private_key');
        if (!empty($recaptchaSecret)) {
            if (empty($recaptchaToken) || !$this->_verifyRecaptcha($recaptchaToken, $recaptchaSecret)) {
                return $this->_jsonResponse(['status' => 'error', 'message' => 'reCAPTCHA verification failed. Please try again.']);
            }
        }

        $email = $request->getUserVar('email');
        $firstName = $request->getUserVar('firstname');
        $lastName = $request->getUserVar('lastname');
        $context = $request->getContext();

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->_jsonResponse(['status' => 'error', 'message' => 'Invalid email address.']);
        }

        if (!$firstName || !$lastName) {
            return $this->_jsonResponse(['status' => 'error', 'message' => 'First and last names are mandatory.']);
        }

        // 0. Guard against null context
        if (!$context) {
            return $request->redirect(null, 'index');
        }

        // 1. Check if user already exists (Include disabled users!)
        $user = Repo::user()->getByEmail($email, true);
        $isNewUser = false;

        if ($user) {
            // Check if they are already a Reader in this context and are active
            $userGroups = Repo::userGroup()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByRoleIds([Role::ROLE_ID_READER])
                ->getMany();
            $readerGroup = $userGroups->first();

            if ($readerGroup) {
                $isSubscribed = Repo::userGroup()->getCollector()
                    ->filterByContextIds([$context->getId()])
                    ->filterByUserIds([$user->getId()])
                    ->filterByUserGroupIds([$readerGroup->getId()])
                    ->getCount() > 0;

                if ($isSubscribed && !$user->getDisabled()) {
                    return $this->_jsonResponse(['status' => 'error', 'message' => 'This email address is already registered.']);
                }
            }
        }

        if (!$user) {
            // 2. Create new user
            $isNewUser = true;
            $user = Repo::user()->newDataObject();

            // Format: [email_local_part]
            $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', strstr($email, '@', true)));

            $user->setUsername($username);
            $user->setEmail($email);
            $user->setPassword(Validation::encryptCredentials($username, bin2hex(random_bytes(16))));

            $user->setGivenName($firstName, Locale::getLocale());
            $user->setFamilyName($lastName, Locale::getLocale());

            // Also set in site primary locale if different
            $sitePrimaryLocale = $request->getSite()->getPrimaryLocale();
            if ($sitePrimaryLocale !== Locale::getLocale()) {
                $user->setGivenName($firstName, $sitePrimaryLocale);
                $user->setFamilyName($lastName, $sitePrimaryLocale);
            }

            $user->setCountry('IN');
            $user->setInlineHelp(1);
            $user->setMustChangePassword(0);
            $user->setDateRegistered(Core::getCurrentDate());

            if (Config::getVar('email', 'require_validation')) {
                $user->setDisabled(true);
                $user->setDisabledReason(__('user.login.accountNotValidated', ['email' => $email]));
            }

            $userId = Repo::user()->add($user);
            // No need to re-fetch $user, it's already updated and we need to keep it for line 126
        }

        // 3. Assign Reader role if not already assigned
        $userGroups = Repo::userGroup()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_READER])
            ->getMany();

        $readerGroup = $userGroups->first();
        if ($user && $readerGroup) {
            $isAssigned = Repo::userGroup()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByUserIds([$user->getId()])
                ->filterByUserGroupIds([$readerGroup->getId()])
                ->getCount() > 0;

            if (!$isAssigned) {
                Repo::userGroup()->assignUserToGroup($user->getId(), $readerGroup->getId());
            }
        }

        // 4. Force validation for newsletter users
        if ($isNewUser) {
            if ($user) {
                // Force disable for validation flow
                $user->setDisabled(true);
                $user->setDisabledReason(__('user.login.accountNotValidated', ['email' => $email]));
                Repo::user()->edit($user);

                $this->_sendValidationEmail($user, $context, $request);
            }
        } else {
            if ($user && $user->getDisabled()) {
                $this->_sendValidationEmail($user, $context, $request);
            }
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return $this->_jsonResponse([
                'status' => 'success',
                'message' => 'Thank you for subscribing! Please check your email to validate your subscription.'
            ]);
        }

        // Fallback for non-AJAX: Redirect back to home with a success flag
        $request->redirect(null, 'index', null, null, ['newsletterSubscribed' => 1]);
    }

    /**
     * Verify reCAPTCHA v2 token with Google API
     */
    private function _verifyRecaptcha($token, $secretKey)
    {
        $data = http_build_query(['secret' => $secretKey, 'response' => $token]);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $data,
                'timeout' => 5,
            ]
        ]);
        $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        if (!$result) return false;
        $json = json_decode($result, true);
        return isset($json['success']) && $json['success'] === true;
    }

    /**
     * Manually send the validation email
     */
    private function _sendValidationEmail($user, $context, $request)
    {
        try {
            $accessKeyManager = new AccessKeyManager();
            $accessKey = $accessKeyManager->createKey(
                'RegisterContext',
                $user->getId(),
                null,
                Config::getVar('email', 'validation_timeout')
            );

            $mailable = new ContextMailable($context);

            $fromEmail = $context->getData('supportEmail');
            $fromName = $context->getData('supportName');

            if (!$fromEmail) {
                $fromEmail = Config::getVar('email', 'default_envelope_sender');
                $fromName = $request->getSite()->getLocalizedContactName();
            }

            $mailable->from($fromEmail, $fromName);

            $activateUrl = $request->url($context->getData('urlPath'), 'user', 'activateUser', [$user->getUsername(), $accessKey]);

            $mailable->addData([
                'activateUrl' => $activateUrl,
            ]);

            $templateKey = $mailable::getEmailTemplateKey();
            $registerTemplate = Repo::emailTemplate()->getByKey($context->getId(), $templateKey);

            if (!$registerTemplate) {
                return;
            }

            $subject = $registerTemplate->getLocalizedData('subject');
            $body = $registerTemplate->getLocalizedData('body');

            $mailable
                ->body($body)
                ->subject($subject)
                ->recipients([$user]);

            Mail::send($mailable);
        } catch (\Exception $e) {
            error_log("Newsletter ERROR: Exception in manual validation dispatch: " . $e->getMessage());
        }
    }

    /**
     * Helper for JSON response
     */
    private function _jsonResponse($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
