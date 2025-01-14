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
 * Validates that a specified field contains at least one of the specified words.
 */
class ContainsOne extends AbstractErrorCheck {
  public function check(): string {
    $checkFailed = '';
    $formValue = trim(strval($this->gp[$this->formFieldName] ?? ''));

    if (strlen($formValue) > 0) {
      $checkValue = GeneralUtility::trimExplode(',', $this->utilityFuncs->getSingle((array) ($this->settings['params'] ?? []), 'words'));
      $found = false;
      foreach ($checkValue as $word) {
        if (stristr($formValue, $word) && !$found) {
          $found = true;
        }
      }
      if (!$found) {
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

    return $checkFailed;
  }

  public function init(array $gp, array $settings): void {
    parent::init($gp, $settings);
    $this->mandatoryParameters = ['words'];
  }
}
