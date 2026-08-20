<?php

namespace Drupal\superfund_blocks;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the Chemical_ID for chemical-page blocks from ?cas= or ?id=.
 *
 * Host classes must have a `protected \Drupal\Core\Database\Connection
 * $database` property.
 */
trait ChemicalIdResolverTrait {

  /**
   * Resolves the numeric Chemical_ID from the ?cas= or ?id= query parameter.
   *
   * ?cas= takes priority when present — it's the human-readable form these
   * pages are moving to. The top-1 Chemical_ID whose cas_number matches is
   * used. Falls back to the numeric ?id= parameter when ?cas= isn't given.
   * Returns NULL if neither resolves to a real chemical, so callers can
   * return an empty block.
   */
  protected function resolveChemicalId(Request $request): ?string {
    $raw_cas       = $request->query->get('cas', '');
    $sanitized_cas = preg_replace('/[^0-9\-]/', '', $raw_cas);

    // CAS Registry Number format: 2-7 digits, a 2-digit group, a check digit
    // (e.g. 50-00-0, 7440-38-2).
    if (preg_match('/^\d{2,7}-\d{2}-\d$/', $sanitized_cas)) {
      $chem_id = $this->database
        ->query(
          'SELECT `Chemical_ID` FROM `view_chemicals` WHERE `cas_number` = :cas ORDER BY `Chemical_ID` LIMIT 1',
          [':cas' => $sanitized_cas]
        )
        ->fetchField();

      return $chem_id !== FALSE ? (string) $chem_id : NULL;
    }

    $raw_id       = $request->query->get('id', '');
    $sanitized_id = preg_replace('/[^0-9\-]/', '', $raw_id);

    if (!preg_match('/^\d+(-\d+)?$/', $sanitized_id)) {
      return NULL;
    }

    $count = (int) $this->database
      ->query(
        'SELECT COUNT(`Chemical_ID`) AS count FROM `view_chemicals` WHERE `Chemical_ID` = :chem_id',
        [':chem_id' => $sanitized_id]
      )
      ->fetchField();

    return $count > 0 ? $sanitized_id : NULL;
  }

}
