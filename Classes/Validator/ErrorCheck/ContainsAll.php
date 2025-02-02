<?php

declare(strict_types=1);

namespace Typoheads\Formhandler\Validator\ErrorCheck;

use TYPO3\CMS\Core\Utility\GeneralUtility;

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
 * Validates that a specified field contains all of the specified words.
 */
class ContainsAll extends AbstractErrorCheck {
  public function check(): string {
    $checkFailed = '';
    $formValue = trim(strval($this->gp[$this->formFieldName] ?? ''));

    if (strlen($formValue) > 0) {
      $checkValue = GeneralUtility::trimExplode(',', $this->utilityFuncs->getSingle((array) ($this->settings['params'] ?? []), 'words'));
      foreach ($checkValue as $idx => $word) {
        if (!stristr($formValue, $word)) {
          // remove userfunc settings and only store comma seperated words
          if (isset($this->settings['params']) && is_array($this->settings['params'])) {
            $this->settings['params']['words'] = implode(',', $checkValue);
          }
          if (isset($this->settings['params']) && is_array($this->settings['params']) && isset($this->settings['params']['words.'])) {
            unset($this->settings['params']['words.']);
          }
          $checkFailed = $this->getCheckFailed();
        }
      }
    }

    return $checkFailed;
  }

  /**
   * @param array<string, mixed> $gp       The get/post parameters
   * @param array<string, mixed> $settings An array with settings
   */
  public function init(array $gp, array $settings): void {
    parent::init($gp, $settings);
    $this->mandatoryParameters = ['words'];
  }
}
