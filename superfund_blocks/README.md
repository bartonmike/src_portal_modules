# Superfund Blocks Module

A Drupal 10 skeleton module for creating multiple custom PHP-powered blocks.

## Installation

1. Copy the `superfund_blocks/` folder to `web/modules/custom/`
2. Enable the module: `drush en superfund_blocks -y` or via **Extend** in the admin UI
3. Place blocks at **Structure → Block Layout**
4. Configure global settings at **Configuration → Content Authoring → Superfund Blocks**

---

## Adding a New Block

1. **Copy an existing block** from `src/Plugin/Block/` — e.g. copy `HelloWorldBlock.php`
2. **Rename the file** to `MyNewBlock.php`
3. **Update the class name** to `MyNewBlock`
4. **Update the `@Block` annotation**:
   ```php
   @Block(
     id = "superfund_blocks_my_new_block",      // unique snake_case ID
     admin_label = @Translation("My New Block"),
     category = @Translation("Superfund Blocks"),
   )
   ```
5. **Write your PHP logic** inside the `build()` method — return a render array
6. **Clear caches**: `drush cr`

The block will now appear in **Structure → Block Layout** for placement.

---

## Block Templates

### Simple block (no services, no config)
Use `HelloWorldBlock.php` as your base.

### Block with per-block admin config
Use `HelloWorldBlock.php` — it shows `blockForm()` and `blockSubmit()`.

### Block with injected Drupal services
Use `CurrentUserBlock.php` — it shows constructor injection via `ContainerFactoryPluginInterface`.

### Block with a database query
Use `RecentNodesBlock.php` — it demonstrates the database service and config together.

---

## Render Array Quick Reference

```php
// Plain HTML markup
return ['#markup' => '<p>Hello</p>'];

// Themed list
return [
  '#theme' => 'item_list',
  '#items' => ['Item 1', 'Item 2'],
  '#title' => 'My List',
];

// Template file (create templates/my-block.html.twig)
return [
  '#theme' => 'my_block',
  '#variable' => $value,
];

// Disable caching
return [
  '#markup' => $dynamic_output,
  '#cache' => ['max-age' => 0],
];

// Cache by user + invalidate on node changes
return [
  '#markup' => $output,
  '#cache' => [
    'contexts' => ['user'],
    'tags'     => ['node_list'],
  ],
];
```

---

## Useful Drupal Services (call via injection or `\Drupal::`)

| Service                   | `\Drupal::` shortcut             | What it does                   |
|---------------------------|----------------------------------|--------------------------------|
| `current_user`            | `\Drupal::currentUser()`         | Logged-in user info            |
| `database`                | `\Drupal::database()`            | Database queries               |
| `entity_type.manager`     | `\Drupal::entityTypeManager()`   | Load nodes, users, terms, etc. |
| `config.factory`          | `\Drupal::config('name')`        | Read config                    |
| `date.formatter`          | `\Drupal::service('date.formatter')` | Format timestamps          |
| `language_manager`        | `\Drupal::languageManager()`     | Current language               |
| `path.current`            | `\Drupal::service('path.current')` | Current URL path             |

---

## File Structure

```
superfund_blocks/
├── superfund_blocks.info.yml          # Module metadata
├── superfund_blocks.permissions.yml   # Permissions
├── superfund_blocks.routing.yml       # Admin settings route
├── superfund_blocks.links.menu.yml    # Admin menu link
├── config/
│   ├── install/
│   │   └── superfund_blocks.settings.yml   # Default config values
│   └── schema/
│       └── superfund_blocks.schema.yml     # Config schema
└── src/
    ├── Form/
    │   └── CustomBlocksSettingsForm.php  # Module-level settings form
    └── Plugin/
        └── Block/
            ├── HelloWorldBlock.php       # Simple block with per-block config
            ├── CurrentUserBlock.php      # Block with service injection
            └── RecentNodesBlock.php      # Block with DB query
```
