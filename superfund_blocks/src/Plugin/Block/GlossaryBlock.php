<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides the Glossary block.
 *
 * Renders the site's static glossary / "how to use this website" page: a
 * sticky sidebar nav plus the documentation content. The content is plain
 * HTML and CSS kept in the module's assets/ folder (glossary.html and
 * glossary.css) so it can be edited without touching PHP.
 *
 * @Block(
 *   id = "superfund_blocks_glossary",
 *   admin_label = @Translation("Glossary"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class GlossaryBlock extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // src/Plugin/Block -> module root is three directories up.
    $assets_dir = dirname(__DIR__, 3) . '/assets';

    $html = @file_get_contents($assets_dir . '/glossary.html');
    if ($html === FALSE) {
      return ['#markup' => ''];
    }

    $css = @file_get_contents($assets_dir . '/glossary.css');

    $build = [
      '#type'   => 'markup',
      // Markup::create() marks the content as already-safe so Drupal skips
      // its Xss::filterAdmin() pass, which would otherwise strip the inline
      // style="..." attributes and the <iframe> video embed. The HTML is
      // developer-authored and static (nothing dynamic goes into it).
      '#markup' => Markup::create($html),
      '#cache'  => [
        'contexts' => [],
      ],
    ];

    if ($css !== FALSE) {
      // Attached via html_head rather than left inline in #markup, since
      // <style> is one of the tags the XSS filter strips.
      $build['#attached']['html_head'][] = [
        [
          '#type'  => 'html_tag',
          '#tag'   => 'style',
          '#value' => $css,
        ],
        'superfund_glossary_style',
      ];
    }

    return $build;
  }

}
