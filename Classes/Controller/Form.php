<?php

declare(strict_types=1);

namespace Typoheads\Formhandler\Controller;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Typoheads\Formhandler\AjaxHandler\AbstractAjaxHandler;
use Typoheads\Formhandler\Debugger\AbstractDebugger;
use Typoheads\Formhandler\Finisher\AbstractFinisher;
use Typoheads\Formhandler\Finisher\Mail;
use Typoheads\Formhandler\Finisher\Redirect;
use Typoheads\Formhandler\Session\AbstractSession;
use Typoheads\Formhandler\Validator\AbstractValidator;
use Typoheads\Formhandler\Validator\DefaultValidator;
use Typoheads\Formhandler\View\AbstractView;

/**
 * This script is part of the TYPO3 project - inspiring people to share!
 *
 * TYPO3 is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by
 * the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 */

/**
 * Default controller for Formhandler.
 */
class Form extends AbstractController {
  /**
   * The current step of the form.
   */
  protected int $currentStep = 0;

  /**
   * Flag indicating if debug mode is on.
   */
  protected bool $debugMode = false;

  /**
   * Contains all errors occurred while validation.
   *
   * @var array<string, mixed>
   */
  protected array $errors = [];

  /**
   * Flag indicating if form is finished (no more steps).
   */
  protected bool $finished = false;

  /**
   * Holds the prefix value of all parameters of this form.
   */
  protected string $formValuesPrefix = '';

  /**
   * The current GET/POST parameters of the form.
   *
   * @var array<string, mixed>
   */
  protected array $gp = [];

  /**
   * The last step of the form.
   */
  protected int $lastStep = 0;

  /**
   * The settings array.
   *
   * @var array<string, mixed>
   */
  protected array $settings = [];

  /**
   * Flag indicating if the form got submitted.
   */
  protected bool $submitted = false;

  /**
   * Total steps of the form.
   */
  protected int $totalSteps = 0;

  /**
   * The view object.
   */
  protected AbstractView $view;

  /**
   * Main method of the form handler.
   *
   * @return string rendered view
   */
  public function process(): string {
    $this->init();
    $this->storeFileNamesInGP();
    $this->processFileRemoval();

    $action = strval(GeneralUtility::_GP('action'));
    if ($this->globals->getFormValuesPrefix()) {
      $temp = (array) GeneralUtility::_GP($this->globals->getFormValuesPrefix());
      $action = strval($temp['action'] ?? '');
    }
    if (!empty($action)) {
      // read template file
      $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
      $this->globals->setTemplateCode($this->templateFile);
      $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
      $this->globals->setLangFiles($this->langFiles);

      $this->view->setLangFiles($this->langFiles);
      $this->view->setSettings($this->settings);

      // reset the template because step had probably been decreased
      $this->setViewSubpart($this->currentStep);
      $content = $this->processAction($action);
      if (strlen(trim($content)) > 0) {
        return $content;
      }
    }

    if (!$this->submitted) {
      return $this->processNotSubmitted();
    }

    return $this->processSubmitted();
  }

  /**
   * Validates the Formhandler config.
   * E.g. If email addresses were set in flexform then Finisher_Mail must exist in the TS configuration.
   */
  public function validateConfig(): void {
    $options = [
      ['to_email', 'sEMAILADMIN', 'finishers', Mail::class],
      ['to_email', 'sEMAILUSER', 'finishers', Mail::class],
      ['redirect_page', 'sMISC', 'finishers', Redirect::class],
      ['required_fields', 'sMISC', 'validators', DefaultValidator::class],
    ];
    foreach ($options as $idx => $option) {
      $fieldName = $option[0];
      $flexformSection = $option[1];
      $component = $option[2];
      $componentName = $option[3];
      $value = $this->utilityFuncs->pi_getFFvalue($this->cObj->data['pi_flexform'] ?? [], $fieldName, $flexformSection);

      // Check if a Mail Finisher can be found in the config
      $isConfigOk = false;
      if (isset($this->settings[$component.'.']) && is_array($this->settings[$component.'.'])) {
        foreach ($this->settings[$component.'.'] as $finisher) {
          $className = $this->utilityFuncs->getPreparedClassName($finisher);
          if ($className == $componentName || @is_subclass_of($className, $componentName)) {
            $isConfigOk = true;

            break;
          }
        }
      }

      if ('' != $value && !$isConfigOk) {
        $this->utilityFuncs->throwException('missing_component', $component, $value, $componentName);
      }
    }
  }

  /**
   * Read stylesheet file(s) set in TypoScript. If set add to header data.
   */
  protected function addCSS(): void {
    $cssFiles = $this->utilityFuncs->parseResourceFiles($this->settings, 'cssFile');
    foreach ($cssFiles as $idx => $fileOptions) {
      if (is_array($fileOptions)) {
        $file = strval($fileOptions['file'] ?? '');
        if (strlen(trim($file)) > 0) {
          $file = $this->utilityFuncs->resolveRelPathFromSiteRoot($file);

          GeneralUtility::makeInstance(PageRenderer::class)->addCssFile(
            $file,
            boolval($fileOptions['alternate'] ?? false) ? 'alternate stylesheet' : 'stylesheet',
            $fileOptions['media'] ?? 'all',
            $fileOptions['title'] ?? '',
            empty($fileOptions['disableCompression']),
            boolval($fileOptions['forceOnTop'] ?? false),
            $fileOptions['allWrap'] ?? '',
            boolval($fileOptions['excludeFromConcatenation'] ?? false)
          );
        }
      }
    }
  }

  /**
   * Adds default configuration for every Formhandler component to the given configuration array.
   *
   * @param array<string, mixed> $conf The configuration of the component set in TS
   *
   * @return array<string, mixed> The initial configuration plus the default configuration
   */
  protected function addDefaultComponentConfig(array $conf): array {
    if (!isset($conf['langFiles'])) {
      $conf['langFiles'] = $this->langFiles;
    }
    $conf['formValuesPrefix'] = $this->settings['formValuesPrefix'] ?? '';
    $conf['templateSuffix'] = $this->settings['templateSuffix'] ?? '';

    return $conf;
  }

  /**
   * Adds a mandatory component to the classes array.
   *
   * @param array<string, mixed> $classesArray
   */
  protected function addFormhandlerClass(array &$classesArray, string $className): void {
    if (0 == count($classesArray)) {
      // add class to the end of the array
      $classesArray[] = ['class' => $className];
    } else {
      $found = false;
      $className = $this->utilityFuncs->prepareClassName($className);
      foreach ($classesArray as $idx => $classOptions) {
        if (is_array($classOptions)) {
          if ($className === $this->utilityFuncs->getPreparedClassName($classOptions)) {
            $found = true;
          }
        }
      }
      if (!$found) {
        // add class to the end of the array
        $classesArray[] = ['class' => $className];
      }
    }
  }

  /**
   * Read JavaScript file(s) set in TypoScript. If set add to header data.
   */
  protected function addJS(): void {
    $jsFiles = $this->utilityFuncs->parseResourceFiles($this->settings, 'jsFile');
    foreach ($jsFiles as $idx => $fileOptions) {
      if (is_array($fileOptions)) {
        $file = strval($fileOptions['file'] ?? '');
        if (strlen(trim($file)) > 0) {
          $file = $this->utilityFuncs->resolveRelPathFromSiteRoot($file);

          GeneralUtility::makeInstance(PageRenderer::class)->addJsFile(
            $file,
            $fileOptions['type'] ?? 'text/javascript',
            empty($fileOptions['disableCompression']),
            boolval($fileOptions['forceOnTop'] ?? false),
            $fileOptions['allWrap'] ?? '',
            boolval($fileOptions['excludeFromConcatenation'] ?? false)
          );
        }
      }
    }
  }

  /**
   * Read JavaScript file(s) set in TypoScript. If set add to footer data.
   */
  protected function addJSFooter(): void {
    $jsFiles = $this->utilityFuncs->parseResourceFiles($this->settings, 'jsFileFooter');
    foreach ($jsFiles as $idx => $fileOptions) {
      if (is_array($fileOptions)) {
        $file = strval($fileOptions['file'] ?? '');

        if (strlen(trim($file)) > 0) {
          $file = $this->utilityFuncs->resolveRelPathFromSiteRoot($file);

          GeneralUtility::makeInstance(PageRenderer::class)->addJsFooterFile(
            $file,
            $fileOptions['type'] ?? 'text/javascript',
            empty($fileOptions['disableCompression']),
            boolval($fileOptions['forceOnTop'] ?? false),
            $fileOptions['allWrap'] ?? '',
            boolval($fileOptions['excludeFromConcatenation'] ?? false)
          );
        }
      }
    }
  }

  /**
   * Searches for current step and sets $this->currentStep according.
   */
  protected function findCurrentStep(): void {
    $action = '';
    $step = 0;
    if (isset($this->gp)) {
      $action = 'reload';
      $keys = array_keys($this->gp);
      foreach ($keys as $idx => $pname) {
        if (strstr($pname, 'step-')) {
          preg_match_all('/step-([0-9]+)-([a-z]+)/', $pname, $matches);
          if (isset($matches[2][0])) {
            $action = $matches[2][0];
            $step = intval($matches[1][0]);
          }
        }
      }
    }

    $allowStepJumps = false;
    if (isset($this->settings['allowStepJumps'])) {
      $allowStepJumps = boolval($this->utilityFuncs->getSingle($this->settings, 'allowStepJumps'));
    }
    $stepInSession = max(intval($this->globals->getSession()?->get('currentStep')), 1);

    switch ($action) {
      case 'prev':
      case 'next':
        if ($step > $stepInSession) {
          if ($allowStepJumps) {
            $this->currentStep = $step;
          } else {
            $this->currentStep = $stepInSession + 1;
          }
        } elseif ($step < $stepInSession) {
          if ($allowStepJumps) {
            $this->currentStep = $step;
          } else {
            $this->currentStep = $stepInSession - 1;
          }
        } else {
          $this->currentStep = $step;
        }

        break;

      default:
        $this->currentStep = $stepInSession;

        break;
    }
    if ($this->currentStep < 1) {
      $this->currentStep = 1;
    }

    $isValidStep = true;
    $disableStepCheck = false;
    if (isset($this->settings['disableStepCheck'])) {
      $disableStepCheck = boolval($this->utilityFuncs->getSingle($this->settings, 'disableStepCheck'));
    }
    if (!$disableStepCheck) {
      for ($i = 1; $i < $this->currentStep - 1; ++$i) {
        $finishedSteps = $this->globals->getSession()?->get('finishedSteps');
        if (is_array($finishedSteps) && !in_array($i, $finishedSteps)) {
          $isValidStep = false;
        }
      }
    }
    $this->utilityFuncs->debugMessage('current_step', [$this->currentStep]);

    if (!$isValidStep) {
      $this->utilityFuncs->throwException('You are not allowed to go to this step!');
    }
  }

  /**
   * Sets the current and last step of the form.
   */
  protected function getStepInformation(): void {
    $this->findCurrentStep();

    $this->lastStep = intval($this->globals->getSession()?->get('currentStep') ?? 1);
    if (0 == $this->lastStep) {
      $this->lastStep = 1;
    }

    $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);

    // Parse all template files and search for step subparts to calculate total step count
    $allTemplateCodes = [];
    if (isset($this->settings['templateFile'])) {
      $allTemplateCodes[] = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
    }
    $step = 1;
    while (isset(((array) ($this->settings[$step.'.'] ?? []))['templateFile'])) {
      $allTemplateCodes[] = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings[$step.'.']);
      ++$step;
    }

    $subparts = [];
    foreach ($allTemplateCodes as $templateCode) {
      preg_match_all('/(###TEMPLATE_FORM)([0-9]+)(_.*)?(###)/', $templateCode, $matches);

      $subparts = array_merge($subparts, array_unique($matches[2]));
    }
    sort($subparts);
    $countSubparts = count($subparts);
    if (0 == $countSubparts) {
      $this->utilityFuncs->debugMessage('subparts_missing', [implode(', ', $subparts)], 2);

      return;
    }
    $this->totalSteps = (int) $subparts[$countSubparts - 1];
    if ($this->totalSteps > $countSubparts) {
      $this->utilityFuncs->debugMessage('subparts_missing', [implode(', ', $subparts)], 2);
    } else {
      $this->utilityFuncs->debugMessage('total_steps', [$this->totalSteps]);
    }
  }

  /**
   * Checks if there are checkbox fields configured for this step.
   * If found, Formhandler sets the correct value of the field(s).
   *
   * @return array<string, mixed>
   */
  protected function handleCheckBoxFields(): array {
    $newGP = $this->utilityFuncs->getMergedGP();

    // check for checkbox fields using the values in $newGP
    if (isset($this->settings['checkBoxFields'])) {
      $checkBoxFields = $this->utilityFuncs->getSingle($this->settings, 'checkBoxFields');
      $fields = empty($checkBoxFields) ? [] : GeneralUtility::trimExplode(',', $checkBoxFields);
      foreach ($fields as $idx => $field) {
        if (!isset($newGP[$field]) && isset($this->gp[$field]) && $this->lastStep < $this->currentStep) {
          $this->gp[$field] = $newGP[$field] = [];

        // Insert default checkbox values
        } elseif (!isset($newGP[$field]) && $this->lastStep < $this->currentStep) {
          if (isset($this->settings['checkBoxUncheckedValue.']) && is_array($this->settings['checkBoxUncheckedValue.']) && isset($this->settings['checkBoxUncheckedValue.'][$field])) {
            $this->gp[$field] = $newGP[$field] = $this->utilityFuncs->getSingle($this->settings['checkBoxUncheckedValue.'], $field);
          } elseif (isset($this->settings['checkBoxUncheckedValue'])) {
            $this->gp[$field] = $newGP[$field] = $this->utilityFuncs->getSingle($this->settings, 'checkBoxUncheckedValue');
          }
        }
      }
    }

    return $newGP;
  }

  /**
   * Init method for the controller.
   * This method sets internal values, initializes the ajax handler and the session.
   */
  protected function init(): void {
    $this->settings = $this->getSettings();
    $this->formValuesPrefix = $this->utilityFuncs->getSingle($this->settings, 'formValuesPrefix');
    $this->globals->setFormID($this->utilityFuncs->getSingle($this->settings, 'formID'));
    $this->globals->setFormValuesPrefix($this->formValuesPrefix);
    $this->globals->setPrefixId($this->utilityFuncs->getSingle($this->settings, 'prefixId'));

    $isDebugMode = $this->utilityFuncs->getSingle($this->settings, 'debug');
    $this->debugMode = (1 === (int) $isDebugMode);

    $this->gp = $this->utilityFuncs->getMergedGP();

    if (!isset($this->settings['uniqueFormID'])) {
      $this->gp['randomID'] = preg_replace('/[^0-9a-z]/', '', preg_quote(strval($this->gp['randomID'] ?? '')));
    }
    $randomID = strval($this->gp['randomID'] ?? '');
    if (empty($randomID)) {
      if (isset($this->settings['uniqueFormID'])) {
        $randomID = $this->utilityFuncs->getSingle($this->settings, 'uniqueFormID');
      } else {
        $randomID = $this->utilityFuncs->generateRandomID();
      }
    }
    $this->globals->setRandomID($randomID);

    $sessionClass = $this->utilityFuncs->getPreparedClassName((array) ($this->settings['session.'] ?? []), 'Session\PHP');

    /** @var AbstractSession $session */
    $session = GeneralUtility::makeInstance($sessionClass, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
    $session->init($this->gp, (array) (((array) ($this->settings['session.'] ?? []))['config.'] ?? []));
    $session->start();
    $this->globals->setSession($session);

    $action = GeneralUtility::_GP('action');
    if ($this->globals->getFormValuesPrefix()) {
      $temp = (array) GeneralUtility::_GP($this->globals->getFormValuesPrefix());
      $action = isset($temp['action']) ? $temp['action'] : null;
    }
    if ((bool) $this->globals->getSession()?->get('finished') && !$action) {
      $this->globals->getSession()?->reset();
      unset($_GET[$this->globals->getFormValuesPrefix()], $_GET['id']);

      $this->utilityFuncs->doRedirect($GLOBALS['TSFE']->id, false, $_GET);

      exit;
    }
    $this->parseConditions();

    $this->initializeDebuggers();

    $this->getStepInformation();

    if (0 == $this->currentStep) {
      $this->currentStep = 1;
      $this->lastStep = 1;
    }

    $this->mergeGPWithSession();

    $this->parseConditions();

    if (0 === (int) $this->utilityFuncs->getSingle($this->settings, 'disableConfigValidation')) {
      $this->validateConfig();
    }
    $this->globals->setSettings($this->settings);

    // set debug mode again cause it may have changed in specific step settings
    $isDebugMode = $this->utilityFuncs->getSingle($this->settings, 'debug');
    $this->debugMode = (1 === (int) $isDebugMode);
    $this->globals->getSession()?->set('debug', $this->debugMode);

    $this->utilityFuncs->debugMessage('using_prefix', [$this->formValuesPrefix]);

    $this->globals->getSession()?->set('predef', $this->globals->getPredef());

    // init view
    $viewClass = $this->utilityFuncs->getPreparedClassName((array) ($this->settings['view.'] ?? []), 'View\Form');
    $this->utilityFuncs->debugMessage('using_view', [$viewClass]);

    $this->utilityFuncs->debugMessage('current_gp', [], 1, $this->gp);

    $this->storeSettingsInSession();

    $this->mergeGPWithSession();

    $this->submitted = $this->isFormSubmitted();

    $this->globals->setSubmitted($this->submitted);
    if (null === $this->globals->getSession()?->get('creationTstamp')) {
      if ($this->submitted) {
        $this->reset($this->gp);
        $this->findCurrentStep();
        $this->globals->getSession()?->set('currentStep', $this->currentStep);
      } else {
        $this->reset();
      }
    }

    $this->addCSS();
    $this->addJS();
    $this->addJSFooter();

    $this->utilityFuncs->debugMessage('current_session_params', [], 1, (array) ($this->globals->getSession()?->get('values') ?: []));

    /** @var AbstractView $view */
    $view = GeneralUtility::makeInstance($viewClass, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);

    $this->view = $view;
    $this->view->setLangFiles($this->langFiles);
    $this->view->setSettings($this->settings);

    $this->globals->setGP($this->gp);

    // init ajax
    if (isset($this->settings['ajax.']) && is_array($this->settings['ajax.'])) {
      $class = $this->utilityFuncs->getPreparedClassName($this->settings['ajax.'], 'AjaxHandler\JQuery');
      $this->utilityFuncs->debugMessage('using_ajax', [$class]);

      /** @var AbstractAjaxHandler $ajaxHandler */
      $ajaxHandler = GeneralUtility::makeInstance($class, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
      $this->globals->setAjaxHandler($ajaxHandler);

      $ajaxHandler->init($this->settings['ajax.']['config.']);
      $ajaxHandler->initAjax();
    }
    if (!isset($this->gp['randomID'])) {
      $this->gp['randomID'] = $this->globals->getRandomID();
    }
  }

  /**
   * Initializes the debuggers set in TS.
   */
  protected function initializeDebuggers(): void {
    if (!isset($this->settings['debuggers.'])) {
      return;
    }

    $this->addFormhandlerClass($this->settings['debuggers.'], 'Typoheads\\Formhandler\\Debugger\\PrintToScreen');

    $debuggers = (array) ($this->settings['debuggers.'] ?? []);
    foreach ($debuggers as $idx => $options) {
      $options = (array) ($options ?? []);
      if (1 != intval($this->utilityFuncs->getSingle($options, 'disable'))) {
        $debuggerClass = $this->utilityFuncs->getPreparedClassName($options);

        /** @var AbstractDebugger $debugger */
        $debugger = GeneralUtility::makeInstance($debuggerClass);
        $debugger->init($this->gp, (array) ($options['config.'] ?? []));
        $debugger->validateConfig();
        $this->globals->addDebugger($debugger);
      }
    }
  }

  /**
   * Checks if the form has been submitted.
   */
  protected function isFormSubmitted(): bool {
    $submitted = (bool) ($this->gp['submitted'] ?? false);
    if ($submitted) {
      foreach ($this->gp as $key => $value) {
        if ('step-' === substr($key, 0, 5)) {
          $submitted = true;
        }
      }
    } elseif (1 === (int) $this->utilityFuncs->getSingle($this->settings, 'skipView')) {
      $submitted = true;
    }

    return $submitted;
  }

  /**
   * Find out if submitted form was valid. If one of the values in the given array $valid is false the submission was not valid.
   *
   * @param array<int, mixed> $validArr Array with the return values of each validator
   */
  protected function isValid(array $validArr): bool {
    $valid = true;
    foreach ($validArr as $idx => $item) {
      if (!$item) {
        $valid = false;
      }
    }

    return $valid;
  }

  /**
   * Loads form settings for a given step.
   *
   * @param int $step The step to load the settings for
   */
  protected function loadSettingsForStep(int $step): void {
    // merge settings with specific settings for current step
    if (isset($this->settings[$step.'.']) && is_array($this->settings[$step.'.'])) {
      $this->settings = $this->utilityFuncs->mergeConfiguration($this->settings, $this->settings[$step.'.']);
    }
    $this->globals->getSession()?->set('settings', $this->settings);
  }

  /**
   * Merges the current GET/POST parameters with the stored ones in SESSION.
   */
  protected function mergeGPWithSession(): void {
    $values = (array) ($this->globals->getSession()?->get('values') ?? []);

    $maxStep = $this->currentStep;
    foreach ($values as $step => &$params) {
      if (is_array($params) && (!$maxStep || $step <= $maxStep)) {
        unset($params['submitted']);
        foreach ($params as $key => $value) {
          if (!isset($this->gp[$key])) {
            $this->gp[$key] = $value;
          }
        }
      }
    }
  }

  /**
   * Method to parse all conditions set in the TS setting "if".
   */
  protected function parseConditions(): void {
    // parse global conditions
    if (isset($this->settings['if.']) && is_array($this->settings['if.'])) {
      $this->settings = $this->utilityFuncs->parseConditionsBlock($this->settings, $this->gp);
    }

    // parse conditions for each of the previous steps
    $endStep = intval($this->globals->getSession()?->get('currentStep') ?? 1);
    $step = 1;

    while ($step <= $endStep) {
      $stepSettings = (array) ($this->settings[$step.'.'] ?? []);
      if (isset($stepSettings['if.']) && is_array($stepSettings['if.'])) {
        $this->settings = $this->utilityFuncs->parseConditionsBlock($stepSettings, $this->gp);
      }
      ++$step;
    }
  }

  /**
   * Internal method to process an action link generated by Finisher_SubmittedOK.
   * This is used to generate a print version or files using submitted form data.
   *
   * @param string $action The action to perform. This must equal "show" for a print version or an action defined in the config of Finisher_SubmittedOK
   *
   * @return string The generated content
   */
  protected function processAction(string $action): string {
    $content = '';
    $gp = $_GET;
    if ($this->globals->getFormValuesPrefix()) {
      $gp = (array) GeneralUtility::_GP($this->globals->getFormValuesPrefix());
    }
    if (isset($this->settings['finishers.']) && is_array($this->settings['finishers.'])) {
      $finisherConf = [];

      foreach ($this->settings['finishers.'] as $key => $config) {
        if (false !== strpos($key, '.')) {
          $className = $this->utilityFuncs->getPreparedClassName($config);
          if ($className === $this->utilityFuncs->prepareClassName('\Typoheads\Formhandler\Finisher\SubmittedOK') && isset($config['config.']) && is_array($config['config.'])) {
            $finisherConf = $config['config.'];
          }
        }
      }

      $params = [];
      $tstamp = intval($gp['tstamp'] ?? 0);
      $hash = $gp['hash'];
      if ($tstamp && false === strpos($hash, ' ')) {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_formhandler_log');
        $stmt = $conn->select(['params'], 'tx_formhandler_log', ['tstamp' => $tstamp, 'unique_hash' => $hash]);
        if (1 === $stmt->rowCount()) {
          $row = (array) ($stmt->fetchAssociative() ?: ['params']);
          $params = (array) unserialize(strval($row['params'] ?? ''));
        }
      }

      if ($finisherConf['actions.'][$action.'.'] && !empty($params) && 1 != intval($this->utilityFuncs->getSingle($finisherConf['actions.'][$action.'.']['config.'], 'returns'))) {
        $class = $this->utilityFuncs->getPreparedClassName($finisherConf['actions.'][$action.'.']);
        if ($class) {
          /** @var AbstractFinisher $object */
          $object = GeneralUtility::makeInstance($class, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
          $object->init($params, $finisherConf['actions.'][$action.'.']['config.']);
          $object->process();
        }
      } elseif ('show' === $action) {
        // "show" makes it possible that Finisher_SubmittedOK show its output again
        $class = $this->utilityFuncs->prepareClassName('\Typoheads\Formhandler\Finisher\SubmittedOK');

        /** @var AbstractFinisher $object */
        $object = GeneralUtility::makeInstance($class, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
        unset($finisherConf['actions.']);
        $object->init($params, $finisherConf);
        $content = strval($object->process());
      } elseif (isset($finisherConf['actions.'][$action.'.']['config.']) && 1 === (int) $this->utilityFuncs->getSingle($finisherConf['actions.'][$action.'.']['config.'], 'returns')) {
        $class = $this->utilityFuncs->getPreparedClassName($finisherConf['actions.'][$action.'.']);
        if ($class) {
          // Makes it possible to make your own Generator class show output

          /** @var AbstractFinisher $object */
          $object = GeneralUtility::makeInstance($class, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
          $object->init($params, $finisherConf['actions.'][$action.'.']['config.']);
          $content = strval($object->process());
        } else {
          // Makes it possible that Finisher_SubmittedOK show its output again
          $class = $this->utilityFuncs->prepareClassName('\Typoheads\Formhandler\Finisher\SubmittedOK');

          /** @var AbstractFinisher $object */
          $object = GeneralUtility::makeInstance($class, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
          unset($finisherConf['actions.']);
          $object->init($params, $finisherConf);
          $content = strval($object->process());
        }
      }
    }

    return $content;
  }

  /**
   * Removes files from the internal file storage.
   */
  protected function processFileRemoval(): void {
    if (isset($this->gp['removeFile']) && (bool) $this->gp['removeFile']) {
      $filename = strval($this->gp['removeFile']);
      $fieldname = strval($this->gp['removeFileField']);
      $sessionFiles = $this->globals->getSession()?->get('files');
      if (is_array($sessionFiles)) {
        foreach ($sessionFiles as $field => $files) {
          if (!strcmp($field, $fieldname)) {
            // get upload folder
            $uploadFolder = $this->utilityFuncs->getTempUploadFolder($field);

            // build absolute path to upload folder
            $uploadPath = $this->utilityFuncs->getTYPO3Root().$uploadFolder;
            $found = false;
            foreach ($files as $key => $fileInfo) {
              if (!strcmp($fileInfo['uploaded_name'], $filename)) {
                $found = true;
                unset($sessionFiles[$field][$key]);
                if (file_exists($uploadPath.$fileInfo['uploaded_name'])) {
                  unlink($uploadPath.$fileInfo['uploaded_name']);
                }
              }
            }
            if (!$found) {
              foreach ($files as $key => $fileInfo) {
                if (!strcmp($fileInfo['name'], $filename)) {
                  unset($sessionFiles[$field][$key]);
                  if (file_exists($uploadPath.$fileInfo['name'])) {
                    unlink($uploadPath.$fileInfo['name']);
                  }
                }
              }
            }
          }
        }
      }
      unset($this->gp['removeFile'], $this->gp['removeFileField']);

      $this->globals->getSession()?->set('files', $sessionFiles);
    }
  }

  /**
   * Processes uploaded files, moves them to a temporary upload folder, renames them if they already exist and
   * stores the information in user session.
   */
  protected function processFiles(): void {
    $sessionFiles = (array) ($this->globals->getSession()?->get('files') ?? []);
    $tempFiles = $sessionFiles;

    if (!empty($_FILES)) {
      $uploadedFilesWithSameNameAction = $this->utilityFuncs->getSingle((array) ($this->settings['files.'] ?? []), 'uploadedFilesWithSameName');
      if (!$uploadedFilesWithSameNameAction) {
        $uploadedFilesWithSameNameAction = 'ignore';
      }

      // for all file properties
      foreach ($_FILES as $sthg => $files) {
        // if a file was uploaded
        if (isset($files['name']) && is_array($files['name'])) {
          // for all file names

          /** @var string $field */
          foreach ($files['name'] as $field => $uploadedFiles) {
            // If only a single file is uploaded
            if (!is_array($uploadedFiles)) {
              $uploadedFiles = [$uploadedFiles];
            }

            if (!isset($this->errors[$field])) {
              // get upload folder
              $uploadFolder = $this->utilityFuncs->getTempUploadFolder($field);

              // build absolute path to upload folder
              $uploadPath = $this->utilityFuncs->getTYPO3Root().$uploadFolder;

              if (!file_exists($uploadPath)) {
                $this->utilityFuncs->debugMessage('folder_doesnt_exist', [$uploadPath], 3);

                return;
              }

              foreach ($uploadedFiles as $idx => $name) {
                $exists = false;
                if (isset($sessionFiles[$field]) && is_array($sessionFiles[$field])) {
                  foreach ($sessionFiles[$field] as $fileId => $fileOptions) {
                    if ($fileOptions['name'] === $name) {
                      $exists = true;
                    }
                  }
                }
                if (!$exists || 'replace' === $uploadedFilesWithSameNameAction || 'append' === $uploadedFilesWithSameNameAction) {
                  $name = $this->utilityFuncs->doFileNameReplace($name);
                  $filename = substr($name, 0, strrpos($name, '.') ?: null);
                  if (strlen($filename) > 0) {
                    $ext = substr($name, strrpos($name, '.') ?: (strlen($name) - 1));
                    $suffix = 1;

                    // build file name
                    $uploadedFileName = $filename.$ext;

                    if ('replace' !== $uploadedFilesWithSameNameAction) {
                      // rename if exists
                      while (file_exists($uploadPath.$uploadedFileName)) {
                        $uploadedFileName = $filename.'_'.$suffix.$ext;
                        ++$suffix;
                      }
                    }
                    $files['name'][$field][$idx] = $uploadedFileName;

                    // move from temp folder to temp upload folder
                    if (!is_array($files['tmp_name'][$field])) {
                      $files['tmp_name'][$field] = [$files['tmp_name'][$field]];
                    }
                    move_uploaded_file($files['tmp_name'][$field][$idx], $uploadPath.$uploadedFileName);
                    GeneralUtility::fixPermissions($uploadPath.$uploadedFileName);
                    $files['uploaded_name'][$field][$idx] = $uploadedFileName;

                    // set values for session
                    $tmp['name'] = $name;
                    $tmp['uploaded_name'] = $uploadedFileName;
                    $tmp['uploaded_path'] = $uploadPath;
                    $tmp['uploaded_folder'] = $uploadFolder;

                    $uploadedUrl = rtrim(strval(GeneralUtility::getIndpEnv('TYPO3_SITE_URL')), '/');
                    $uploadedUrl .= '/'.trim($uploadFolder, '/').'/';
                    $uploadedUrl .= trim($uploadedFileName, '/');

                    $tmp['uploaded_url'] = $uploadedUrl;
                    $tmp['size'] = $files['size'][$field][$idx];
                    if (is_array($files['type'][$field][$idx])) {
                      $tmp['type'] = $files['type'][$field][$idx];
                    } else {
                      $tmp['type'] = $files['type'][$field];
                    }
                    $tempFile = [];
                    if (strlen($field) > 0 && is_array($tempFiles[$field] ?? null)) {
                      $tempFile = (array) $tempFiles[$field];
                    }
                    if (!$exists || 'replace' !== $uploadedFilesWithSameNameAction) {
                      array_push($tempFile, $tmp);
                    }
                    $tempFiles[$field] = $tempFile;
                    if (!is_array($this->gp[$field] ?? '')) {
                      $this->gp[$field] = [];
                    }
                    if (!$exists || 'replace' !== $uploadedFilesWithSameNameAction) {
                      array_push($this->gp[$field], $uploadedFileName);
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    $this->globals->getSession()?->set('files', $tempFiles);
    $this->utilityFuncs->debugMessage('Files:', [], 1, (array) $tempFiles);
  }

  /**
   * Process a form containing no more steps (a form which is finished).
   *
   * @return null|array<string, mixed>|string Output of a Finisher
   */
  protected function processFinished(): null|array|string {
    // If skipView is set, call preProcessors and initInterceptors here
    if (1 == intval($this->utilityFuncs->getSingle($this->settings, 'skipView'))) {
      // run preProcessors
      $output = $this->runClasses((array) ($this->settings['preProcessors.'] ?? []));
      if (!empty($output)) {
        return $output;
      }

      // run init interceptors
      if (!isset($this->settings['initInterceptors.']) || !is_array($this->settings['initInterceptors.'])) {
        $this->settings['initInterceptors.'] = [];
      }
      $this->addFormhandlerClass($this->settings['initInterceptors.'], 'Interceptor\\RemoveXSS');
      $output = $this->runClasses((array) ($this->settings['initInterceptors.'] ?? []));
      if (!empty($output)) {
        return $output;
      }
    }
    $this->storeSettingsInSession();

    // run save interceptors
    if (!isset($this->settings['saveInterceptors.']) || !is_array($this->settings['saveInterceptors.'])) {
      $this->settings['saveInterceptors.'] = [];
    }
    $this->addFormhandlerClass($this->settings['saveInterceptors.'], 'Interceptor\\RemoveXSS');
    $output = $this->runClasses((array) ($this->settings['saveInterceptors.'] ?? []));
    if (!empty($output)) {
      return $output;
    }

    // run loggers
    if (!isset($this->settings['loggers.']) || !is_array($this->settings['loggers.'])) {
      $this->settings['loggers.'] = [];
    }
    $this->addFormhandlerClass($this->settings['loggers.'], 'Logger_DB');
    $output = $this->runClasses((array) ($this->settings['loggers.'] ?? []));
    if (!empty($output)) {
      return $output;
    }

    // run finishers
    if (isset($this->settings['finishers.']) && is_array($this->settings['finishers.']) && 1 !== (int) $this->utilityFuncs->getSingle($this->settings['finishers.'], 'disable')) {
      ksort($this->settings['finishers.']);

      foreach ($this->settings['finishers.'] as $idx => $tsConfig) {
        if ('disabled' !== $idx) {
          $className = $this->utilityFuncs->getPreparedClassName($tsConfig);
          if (is_array($tsConfig) && !empty($className)) {
            if (1 !== (int) $this->utilityFuncs->getSingle($tsConfig, 'disable')) {
              /** @var AbstractFinisher $finisher */
              $finisher = GeneralUtility::makeInstance($className, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
              $tsConfig['config.'] = $this->addDefaultComponentConfig($tsConfig['config.'] ?? []);
              $finisher->init($this->gp, $tsConfig['config.']);
              $finisher->validateConfig();

              // if the finisher returns HTML (e.g. Typoheads\Formhandler\Finisher\SubmittedOK)
              if (1 == intval($this->utilityFuncs->getSingle($tsConfig['config.'], 'returns'))) {
                $this->globals->getSession()?->set('finished', true);

                return $finisher->process();
              }

              $return = $finisher->process();
              if (is_array($return)) {
                $this->gp = $return;
                $this->globals->setGP($this->gp);
              }
            }
          } else {
            $this->utilityFuncs->throwException('classesarray_error');
          }
        }
      }
      $this->globals->getSession()?->set('finished', true);
    }

    return null;
  }

  /**
   * Process a form which has not been submitted.
   *
   * @return string Rendered form
   */
  protected function processNotSubmitted(): string {
    $this->loadSettingsForStep($this->currentStep);
    $this->parseConditions();

    $this->view->setSettings($this->settings);

    $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
    $this->globals->setTemplateCode($this->templateFile);
    $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
    $this->globals->setLangFiles($this->langFiles);

    $this->view->setLangFiles($this->langFiles);
    $this->setViewSubpart($this->currentStep);

    $output = $this->runClasses((array) ($this->settings['preProcessors.'] ?? []));
    if (!empty($output)) {
      return $output;
    }

    if (!isset($this->settings['initInterceptors.'])) {
      $this->settings['initInterceptors.'] = [];
    }
    $this->addFormhandlerClass($this->settings['initInterceptors.'], 'Interceptor\\RemoveXSS');
    $output = $this->runClasses((array) ($this->settings['initInterceptors.'] ?? []));
    if (!empty($output)) {
      return $output;
    }

    // Parse conditions again. An interceptor might have added additional values.
    $this->parseConditions();
    $this->loadSettingsForStep($this->currentStep);

    return $this->view->render($this->gp, $this->errors);
  }

  /**
   * Process a form containing errors.
   *
   * @return string Rendered form
   */
  protected function processNotValid(): string {
    $this->gp['formErrors'] = $this->errors;
    $this->globals->setGP($this->gp);

    // stay on current step
    if ($this->lastStep < intval($this->globals->getSession()?->get('currentStep') ?? 1)) {
      $this->globals->getSession()?->set('currentStep', $this->lastStep);
      $this->currentStep = $this->lastStep;
    }

    // load settings from last step again because an error occurred
    $this->loadSettingsForStep($this->currentStep);
    $this->globals->getSession()?->set('settings', $this->settings);

    // read template file
    $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
    $this->globals->setTemplateCode($this->templateFile);
    $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
    $this->globals->setLangFiles($this->langFiles);

    $this->view->setLangFiles($this->langFiles);
    $this->view->setSettings($this->settings);

    // reset the template because step had probably been decreased
    $this->setViewSubpart($this->currentStep);

    if ($this->currentStep >= $this->lastStep) {
      $this->storeGPinSession();
      $this->mergeGPWithSession();
    }

    return $this->view->render($this->gp, $this->errors);
  }

  /**
   * Process the form if the user clicked submit.
   *
   * @return string The generated content
   */
  protected function processSubmitted(): string {
    /*
     * Step may have been set to the next step already.
     * Set the settings back to the one of the previous step
     * to run the right interceptors and validators.
     */
    if ($this->currentStep > $this->lastStep) {
      $this->loadSettingsForStep($this->lastStep);
    } else {
      $this->loadSettingsForStep($this->currentStep);
    }

    $this->parseConditions();

    if ($this->currentStep > $this->lastStep) {
      $this->loadSettingsForStep($this->lastStep);
    } else {
      $this->loadSettingsForStep($this->currentStep);
    }

    // run init interceptors
    if (!isset($this->settings['initInterceptors.'])) {
      $this->settings['initInterceptors.'] = [];
    }
    $this->addFormhandlerClass($this->settings['initInterceptors.'], '\Typoheads\Formhandler\Interceptor\RemoveXSS');
    $output = $this->runClasses((array) ($this->settings['initInterceptors.'] ?? []));
    if (!empty($output)) {
      return $output;
    }

    // Search for completely unchecked checkbox arrays before validation to make sure that no values from session are taken.
    if ($this->currentStep > $this->lastStep) {
      $currentGP = $this->utilityFuncs->getMergedGP();
      if (isset($this->settings['checkBoxFields'])) {
        $checkBoxFields = $this->utilityFuncs->getSingle($this->settings, 'checkBoxFields');
        $fields = empty($checkBoxFields) ? [] : GeneralUtility::trimExplode(',', $checkBoxFields);
        foreach ($fields as $idx => $field) {
          if (isset($this->gp[$field]) && !isset($currentGP[$field])) {
            unset($this->gp[$field]);
          }
        }
      }
      $this->globals->setGP($this->gp);
    }

    // Parse conditions again. An interceptor might have added additional values.
    $this->parseConditions();

    if ($this->currentStep > $this->lastStep) {
      $this->loadSettingsForStep($this->lastStep);
    } else {
      $this->loadSettingsForStep($this->currentStep);
    }

    $this->globals->setRandomID(strval($this->gp['randomID'] ?? ''));

    // run validation
    $this->errors = [];
    $valid = [true];
    if ($this->currentStep >= $this->lastStep) {
      $this->validateErrorCheckConfig();
    }
    if (isset($this->settings['validators.'])
      && is_array($this->settings['validators.'])
      && 1 != intval($this->utilityFuncs->getSingle($this->settings['validators.'], 'disable'))
    ) {
      foreach ($this->settings['validators.'] as $idx => $tsConfig) {
        if ('disable' !== $idx) {
          $className = $this->utilityFuncs->getPreparedClassName($tsConfig);
          if (is_array($tsConfig) && !empty($className)) {
            if (1 !== (int) $this->utilityFuncs->getSingle($tsConfig, 'disable')) {
              /** @var AbstractValidator $validator */
              $validator = GeneralUtility::makeInstance($className, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
              if ($this->currentStep === $this->lastStep) {
                $restrictErrorChecks = $this->utilityFuncs->getSingle($tsConfig['config.'] ?? [], 'restrictErrorChecks');
                $userSetting = empty($restrictErrorChecks) ? [] : GeneralUtility::trimExplode(',', $restrictErrorChecks);
                $autoSetting = [
                  'fileAllowedTypes',
                  'fileRequired',
                  'fileMaxCount',
                  'fileMinCount',
                  'fileMaxSize',
                  'fileMinSize',
                  'fileMaxTotalSize',
                ];
                $merged = array_merge($userSetting, $autoSetting);
                $tsConfig['config.']['restrictErrorChecks'] = implode(',', $merged);
                unset($tsConfig['config.']['restrictErrorChecks.']);
              }
              $tsConfig['config.'] = $this->addDefaultComponentConfig($tsConfig['config.'] ?? []);
              $validator->init($this->gp, $tsConfig['config.']);
              $validator->validateConfig();
              $res = $validator->validate($this->errors);
              array_push($valid, $res);
            }
          } else {
            $this->utilityFuncs->throwException('classesarray_error');
          }
        }
      }
    }

    // process files
    if ($this->currentStep >= $this->lastStep) {
      $this->processFiles();
    }

    // if form is valid
    if ($this->isValid($valid)) {
      $this->loadSettingsForStep($this->currentStep);
      $this->parseConditions();

      // read template file
      $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
      $this->globals->setTemplateCode($this->templateFile);
      $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
      $this->globals->setLangFiles($this->langFiles);

      $this->view->setLangFiles($this->langFiles);
      $this->view->setSettings($this->settings);
      $this->setViewSubpart($this->currentStep);

      $this->storeGPinSession();
      $this->mergeGPWithSession();

      // mark step as finished
      $finishedSteps = $this->globals->getSession()?->get('finishedSteps');
      if (!is_array($finishedSteps)) {
        $finishedSteps = [];
      }

      if ($this->currentStep > $this->lastStep && !in_array($this->currentStep - 1, $finishedSteps)) {
        $finishedSteps[] = $this->currentStep - 1;
      }
      $this->globals->getSession()?->set('finishedSteps', $finishedSteps);

      // if no more steps
      if ($this->finished) {
        $finished = $this->processFinished();
        if (is_array($finished)) {
          return strval(current($finished));
        }

        return $finished ?? '';
      }

      return $this->view->render($this->gp, $this->errors);
    }
    $this->templateFile = $this->utilityFuncs->readTemplateFile($this->templateFile, $this->settings);
    $this->globals->setTemplateCode($this->templateFile);
    $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
    $this->globals->setLangFiles($this->langFiles);

    $this->view->setLangFiles($this->langFiles);
    $this->view->setSettings($this->settings);
    $this->setViewSubpart($this->currentStep);

    return $this->processNotValid();
  }

  /**
   * Resets the values in session to have a clean form.
   *
   * @param array<string, mixed> $gp
   */
  protected function reset(array $gp = []): void {
    $values = [
      'creationTstamp' => time(),
      'values' => null,
      'files' => null,
      'lastStep' => null,
      'currentStep' => 1,
      'startblock' => null,
      'endblock' => null,
      'inserted_uid' => null,
      'inserted_tstamp' => null,
      'key_hash' => null,
      'finished' => null,
      'finishedSteps' => [],
    ];
    $this->globals->getSession()?->setMultiple($values);
    $this->gp = $gp;
    $this->currentStep = 1;
    $this->globals->setGP($this->gp);
    $this->utilityFuncs->debugMessage('cleared_session');
  }

  /**
   * Runs the class by calling process() method.
   *
   * @param array<string, mixed> $classesArray : the configuration array
   */
  protected function runClasses(array $classesArray): ?string {
    if (1 != intval($this->utilityFuncs->getSingle($classesArray, 'disable'))) {
      ksort($classesArray);

      // Load language files everytime before running a component. They may have been changed by previous components
      $this->langFiles = $this->utilityFuncs->readLanguageFiles($this->langFiles, $this->settings);
      $this->globals->setLangFiles($this->langFiles);
      foreach ($classesArray as $idx => $tsConfig) {
        if ('disable' !== $idx) {
          if (is_array($tsConfig)) {
            $className = $this->utilityFuncs->getPreparedClassName($tsConfig);
            if (!empty($className)) {
              if (1 != intval($this->utilityFuncs->getSingle($tsConfig, 'disable'))) {
                $this->utilityFuncs->debugMessage('calling_class', [$className]);

                /** @var AbstractValidator $obj */
                $obj = GeneralUtility::makeInstance($className, $this->componentManager, $this->configuration, $this->globals, $this->utilityFuncs);
                $tsConfig['config.'] = $this->addDefaultComponentConfig($tsConfig['config.'] ?? []);
                $obj->init($this->gp, $tsConfig['config.']);
                $obj->validateConfig();
                $return = $obj->process();
                if (is_array($return)) {
                  // return value is an array. Treat it as the probably modified get/post parameters
                  $this->gp = $return;
                  $this->globals->setGP($this->gp);
                } else {
                  // return value is no array. treat this return value as output.
                  return $return;
                }
              }
            } else {
              $this->utilityFuncs->throwException('classesarray_error');
            }
          } else {
            $this->utilityFuncs->throwException('classesarray_error');
          }
        }
      }
    }

    return null;
  }

  /**
   * Sets the template of the view.
   *
   * @param int $step The current step
   */
  protected function setViewSubpart(int $step): void {
    $this->finished = false;

    if (1 == intval($this->utilityFuncs->getSingle($this->settings, 'skipView'))) {
      $this->finished = true;
    } elseif (!isset($this->settings['templateSuffix']) && strstr($this->templateFile, '###TEMPLATE_FORM'.$step.'###')) {
      // search for ###TEMPLATE_FORM[step]###
      $this->utilityFuncs->debugMessage('using_subpart', ['###TEMPLATE_FORM'.$step.'###']);
      $this->view->setTemplate($this->templateFile, 'FORM'.$step);
    } elseif (isset($this->settings['templateSuffix']) && strstr($this->templateFile, '###TEMPLATE_FORM'.$step.$this->settings['templateSuffix'].'###')) {
      // search for ###TEMPLATE_FORM[step][suffix]###
      $this->utilityFuncs->debugMessage('using_subpart', ['###TEMPLATE_FORM'.$step.$this->settings['templateSuffix'].'###']);
      $this->view->setTemplate($this->templateFile, 'FORM'.$step.$this->settings['templateSuffix']);
    } elseif ($step == intval($this->globals->getSession()?->get('lastStep') ?? 1) + 1) {
      $this->finished = true;
    }
  }

  /**
   * Stores file names of uploaded files into the internal GET/POST parameters storage ($this->gp) so that they can be used later on in "value markers", userFuncs, ...
   */
  protected function storeFileNamesInGP(): void {
    // put file names into $this->gp
    $sessionFiles = $this->globals->getSession()?->get('files');
    if (!is_array($sessionFiles)) {
      $sessionFiles = [];
    }
    foreach ($sessionFiles as $fieldname => $files) {
      $fileNames = [];
      if (is_array($files)) {
        foreach ($files as $idx => $fileInfo) {
          $fileName = $fileInfo['uploaded_name'];
          if (!$fileName) {
            $fileName = $fileInfo['name'];
          }
          $fileNames[] = $fileName;
        }
      }
      $this->gp[$fieldname] = implode(',', $fileNames);
    }
  }

  /**
   * Stores the current GET/POST parameters in SESSION.
   */
  protected function storeGPinSession(): void {
    if ($this->currentStep > $this->lastStep) {
      $this->loadSettingsForStep($this->lastStep);
    }
    $newGP = $this->handleCheckBoxFields();
    if ($this->currentStep > $this->lastStep) {
      $this->loadSettingsForStep($this->currentStep);
    }
    $data = (array) ($this->globals->getSession()?->get('values') ?? []);

    $checkBoxFields = $this->utilityFuncs->getSingle($this->settings, 'checkBoxFields');
    $checkBoxFields = empty($checkBoxFields) ? [] : GeneralUtility::trimExplode(',', $checkBoxFields);

    // set the variables in session
    if ($this->lastStep !== $this->currentStep) {
      foreach ($newGP as $key => $value) {
        if (!strstr($key, 'step-') && 'submitted' !== $key && 'randomID' !== $key
          && 'removeFile' !== $key && 'removeFileField' !== $key && 'submitField' !== $key
        ) {
          $dataStep = [];
          if (isset($data[$this->lastStep]) && is_array($data[$this->lastStep])) {
            $dataStep = $data[$this->lastStep];
          }
          $dataStep[$key] = $value;
          $data[$this->lastStep] = $dataStep;
        }
      }
    }

    // Search for checkboxes which were unchecked in this step.
    foreach ($checkBoxFields as $field) {
      if (!isset($newGP[$field]) && isset($data[$this->lastStep]) && is_array($data[$this->lastStep]) && isset($data[$this->lastStep][$field])) {
        unset($data[$this->lastStep][$field]);
      }
    }
    $this->globals->getSession()?->set('values', $data);
  }

  /**
   * Stores some settings of the form into the session.
   */
  protected function storeSettingsInSession(): void {
    $values = [
      'formValuesPrefix' => $this->formValuesPrefix,
      'settings' => $this->settings,
      'debug' => $this->debugMode,
      'currentStep' => $this->currentStep,
      'totalSteps' => $this->totalSteps,
      'lastStep' => $this->lastStep,
      'templateSuffix' => $this->settings['templateSuffix'] ?? '',
    ];
    $this->globals->getSession()?->setMultiple($values);
    $this->globals->setFormValuesPrefix($this->formValuesPrefix);
    $this->globals->setTemplateSuffix(strval($this->settings['templateSuffix'] ?? ''));
  }

  /**
   * Validate if the error checks have all been set correctly.
   */
  protected function validateErrorCheckConfig(): void {
    if (!empty($_FILES)) {
      // for all file properties
      foreach ($_FILES as $sthg => $files) {
        // if a file upload field exists
        if (isset($files['name']) && is_array($files['name'])) {
          // for all file names
          $uploadFields = array_keys($files['name']);
          foreach ($uploadFields as $field) {
            // if a file was uploaded through this field
            if (!is_array($files['tmp_name'][$field])) {
              $files['tmp_name'][$field] = [$files['tmp_name'][$field]];
            }
            if (count($files['tmp_name'][$field]) > 0) {
              $hasAllowedTypesCheck = false;
              if (isset($this->settings['validators.'])
                  && is_array($this->settings['validators.'])
                  && 1 !== intval($this->utilityFuncs->getSingle($this->settings['validators.'], 'disable'))
              ) {
                foreach ($this->settings['validators.'] as $idx => $tsConfig) {
                  if ($tsConfig['config.']['fieldConf.'][$field.'.']['errorCheck.']) {
                    foreach ($tsConfig['config.']['fieldConf.'][$field.'.']['errorCheck.'] as $errorCheck) {
                      if ('fileAllowedTypes' === $errorCheck) {
                        $hasAllowedTypesCheck = true;
                      }
                    }
                  }
                }
              }
              if (!$hasAllowedTypesCheck) {
                $missingChecks = [];
                $missingChecks[] = 'fileAllowedTypes';
                $this->utilityFuncs->throwException('error_checks_missing', implode(',', $missingChecks), $field);
              }
            }
          }
        }
      }
    }
  }
}
