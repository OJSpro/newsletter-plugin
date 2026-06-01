<?php

/**
 * @file plugins/generic/newsletter/NewsletterPlugin.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NewsletterPlugin
 * @brief Newsletter subscription plugin main class
 */

namespace APP\plugins\generic\newsletter;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\config\Config;

class NewsletterPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('LoadHandler', [$this, 'callbackHandleContent']);
                Hook::add('TemplateManager::display', [$this, 'assignRecaptchaKey']);
            }
            return true;
        }
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return 'Newsletter Subscription';
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return 'Enables newsletter subscription by registering users as Readers in OMP.';
    }

    /**
     * Assign reCAPTCHA public key to all frontend templates so newsletter forms can render the widget
     */
    public function assignRecaptchaKey($hookName, $args)
    {
        $templateMgr = $args[0];
        if (Config::getVar('captcha', 'recaptcha') && Config::getVar('captcha', 'recaptcha_public_key')) {
            $templateMgr->assign('recaptchaPublicKey', Config::getVar('captcha', 'recaptcha_public_key'));
        }
        return false;
    }

    /**
     * Declare the handler function to process the newsletter/subscribe path
     */
    public function callbackHandleContent($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];
        $handler = &$args[3];

        if ($page === 'newsletter' && $op === 'subscribe') {
            require_once($this->getPluginPath() . '/NewsletterHandler.php');
            $handler = new NewsletterHandler($this);
            return true;
        }

        return false;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\newsletter\NewsletterPlugin', '\NewsletterPlugin');
}
