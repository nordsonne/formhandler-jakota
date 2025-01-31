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
 * Validates that a specified field value is a valid URL.
 */
class Url extends AbstractErrorCheck {
  /**
   * Validates that a specified field has valid url syntax.
   *
   * @return string The error string
   */
  public function check(): string {
    $checkFailed = '';

    $formFieldValue = strval($this->gp[$this->formFieldName] ?? '');
    if (strlen(trim($formFieldValue)) > 0) {
      $valid = GeneralUtility::isValidUrl($formFieldValue);
      if (!$valid) {
        $checkFailed = $this->getCheckFailed();
      }
    }

    return $checkFailed;
  }
}
