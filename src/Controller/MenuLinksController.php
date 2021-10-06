<?php

namespace Drupal\structure_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\structure_sync\StructureSyncHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Controller for syncing menu links.
 */
class MenuLinksController extends ControllerBase
{

  private $config;

  /**
   * Constructor for menu links controller.
   */
  public function __construct()
  {
    $this->config = $this->getEditableConfig();
    $this->entityTypeManager();
  }

  /**
   * Gets the editable version of the config.
   */
  private function getEditableConfig()
  {
    $this->config('structure_sync.data');

    return $this->configFactory->getEditable('structure_sync.data');
  }

  /**
   * Function to export menu links.
   */
  public function exportMenuLinks(array $form = NULL, FormStateInterface $form_state = NULL)
  {
    StructureSyncHelper::logMessage('Menu links export started');
    $languages_enabled = \Drupal::languageManager()->getLanguages();
    $languagesPriority = [];

    if (is_object($form_state) && $form_state->hasValue('export_menu_list')) {
      $menu_list = $form_state->getValue('export_menu_list');
      $menu_list = array_filter($menu_list, 'is_string');
    }

    $this->config->clear('menus')->save();

    if (isset($menu_list)) {
      $menuLinks = [];

      foreach ($menu_list as $menu_name) {
        $menuLinks = array_merge($this->entityTypeManager
          ->getStorage('menu_link_content')
          ->loadByProperties(['menu_name' => $menu_name]), $menuLinks);
      }
    } else {
      $menuLinks = $this->entityTypeManager()->getStorage('menu_link_content')
        ->loadMultiple();
    }

    $customMenuLinks = [];
    foreach ($menuLinks as $menuLink) {
      $customMenuLink = [];
      $field_defs = $menuLink->getFieldDefinitions();
      $mid = $menuLink->id();

      // Sort the languages to be exported by priority
      $default_language = $menuLink->get('langcode')->value;
      $languagesPriority = $languages_enabled;
      unset($languagesPriority[$default_language]);
      array_unshift($languagesPriority, $languages_enabled[$default_language]);

      foreach ($languagesPriority as $language) {
        $language_id = $language->getId();
        if ($menuLink->hasTranslation($language_id)) {
          $customMenuLink[$mid][$language_id]['id'] = $menuLink->id();
          foreach ($field_defs as $field_name => $value) {
            if (!array_key_exists($field_name, $customMenuLink[$mid][$language_id])) {
              if ($field_name == 'link') {
                $customMenuLink[$mid][$language_id][$field_name] = $menuLink->getTranslation($language_id)->{$field_name}->getValue()[0];
              } else {
                $customMenuLink[$mid][$language_id][$field_name] = $menuLink->getTranslation($language_id)->{$field_name}->value;
              }
            }
          }
          if (array_key_exists('drush', $form) && $form['drush'] === TRUE) {
            drush_log('Exported "' . $customMenuLink[$mid][$language_id]['title'] . '" of menu "' . $customMenuLink[$mid][$language_id]['menu_name'] . '" (' . $language_id . ')', 'ok');
          }
          StructureSyncHelper::logMessage('Exported "' . $customMenuLink[$mid][$language_id]['title'] . '" of menu "' . $customMenuLink[$mid][$language_id]['menu_name'] . '" (' . $language_id . ')');
        }
      }

      $customMenuLinks[] = $customMenuLink[$mid];
    }

    $this->config->set('menus', $customMenuLinks)->save();

    drupal_set_message($this->t('The menu links have been successfully exported.'));
    StructureSyncHelper::logMessage('Menu links exported');
  }

  /**
   * Function to import menu links.
   *
   * When this function is used without the designated form, you should assign
   * an array with a key value pair for form with key 'style' and value 'full',
   * 'safe' or 'force' to apply that import style.
   */
  public function importMenuLinks(array $form, FormStateInterface $form_state = NULL)
  {
    StructureSyncHelper::logMessage('Menu links import started');

    // Check if the there is a selection made in a form for what menus need to
    // be imported.
    if (is_object($form_state) && $form_state->hasValue('import_menu_list')) {
      $menusSelected = $form_state->getValue('import_menu_list');
      $menusSelected = array_filter($menusSelected, 'is_string');
    }
    if (array_key_exists('style', $form)) {
      $style = $form['style'];
    } else {
      StructureSyncHelper::logMessage('No style defined on menu links import', 'error');
      return;
    }

    StructureSyncHelper::logMessage('Using "' . $style . '" style for menu links import');

    // Get menu links from config.
    $menusConfig = $this->config->get('menus');

    $menus = [];

    if (isset($menusSelected)) {
      foreach ($menusConfig as $menu) {
        if (in_array($menu['menu_name'], array_keys($menusSelected))) {
          $menus[] = $menu;
        }
      }
    } else {
      $menus = $menusConfig;
    }

    if (array_key_exists('drush', $form) && $form['drush'] === TRUE) {
      $context = [];
      $context['drush'] = TRUE;

      switch ($style) {
        case 'full':
          self::deleteDeletedMenuLinks($menus, $context);
          self::importMenuLinksFull($menus, $context);
          self::menuLinksImportFinishedCallback(NULL, NULL, NULL);
          break;

        case 'safe':
          self::importMenuLinksSafe($menus, $context);
          self::menuLinksImportFinishedCallback(NULL, NULL, NULL);
          break;

        case 'force':
          self::deleteMenuLinks($context);
          self::importMenuLinksForce($menus, $context);
          self::menuLinksImportFinishedCallback(NULL, NULL, NULL);
          break;
      }

      return;
    }

    // Import the menu links with the chosen style of importing.
    switch ($style) {
      case 'full':
        $batch = [
          'title' => $this->t('Importing menu links...'),
          'operations' => [
            [
              '\Drupal\structure_sync\Controller\MenuLinksController::deleteDeletedMenuLinks',
              [$menus],
            ],
            [
              '\Drupal\structure_sync\Controller\MenuLinksController::importMenuLinksFull',
              [$menus],
            ],
          ],
          'finished' => '\Drupal\structure_sync\Controller\MenuLinksController::menuLinksImportFinishedCallback',
        ];
        batch_set($batch);
        break;

      case 'safe':
        $batch = [
          'title' => $this->t('Importing menu links...'),
          'operations' => [
            [
              '\Drupal\structure_sync\Controller\MenuLinksController::importMenuLinksSafe',
              [$menus],
            ],
          ],
          'finished' => '\Drupal\structure_sync\Controller\MenuLinksController::menuLinksImportFinishedCallback',
        ];
        batch_set($batch);
        break;

      case 'force':
        $batch = [
          'title' => $this->t('Importing menu links...'),
          'operations' => [
            [
              '\Drupal\structure_sync\Controller\MenuLinksController::deleteMenuLinks',
              [],
            ],
            [
              '\Drupal\structure_sync\Controller\MenuLinksController::importMenuLinksForce',
              [$menus],
            ],
          ],
          'finished' => '\Drupal\structure_sync\Controller\MenuLinksController::menuLinksImportFinishedCallback',
        ];
        batch_set($batch);
        break;

      default:
        StructureSyncHelper::logMessage('Style not recognized', 'error');
        break;
    }
  }

  /**
   * Function to delete the menu links that should be removed in this import.
   */
  public static function deleteDeletedMenuLinks($menus, &$context)
  {
    $uuidsInConfig = [];
    $midLangConfig = [];
    $midLangDb = [];
    $midLangToDelete = [];
    foreach ($menus as $menuLink) {
      foreach ($menuLink as $language_id => $link) {
        $midLangConfig[] = $link['id'] . '.' . $language_id;
        $uuidsInConfig[] = $link['uuid'];
      }
    }
    // Remove duplicates
    $uuidsInConfig = array_unique($uuidsInConfig);

    // Completely delete terms that are not in the exported configuration
    if (!empty($uuidsInConfig) && count($uuidsInConfig) > 0) {
      $query = StructureSyncHelper::getEntityQuery('menu_link_content');
      $query->condition('uuid', $uuidsInConfig, 'NOT IN');
      $ids = $query->execute();
      $controller = StructureSyncHelper::getEntityManager()
        ->getStorage('menu_link_content');
      $entities = $controller->loadMultiple($ids);
      $controller->delete($entities);

      // Check if any translation of the term should be deleted
      $query = \Drupal::database()->select('menu_link_content_data', 'mlcd');
      $query->fields('mlcd', ['id', 'langcode']);
      $result = $query->execute();
      while ($record = $result->fetchAssoc()) {
        $midLangDb[] = $record['id'] . '.' . $record['langcode'];
      }
      $midLangToDelete = array_diff($midLangDb, $midLangConfig);
      // Delete translations of the term
      if (count($midLangToDelete) > 0) {
        foreach ($midLangToDelete as $value) {
          $divkey = explode('.', $value);
          $mid = $divkey[0];
          $language_id = $divkey[1];
          $link_loaded = \Drupal::entityTypeManager()->getStorage('menu_link_content')->load($mid);
          if ($link_loaded->hasTranslation($language_id)) {
            if ($link_loaded->getUntranslated()->content_translation_source->value == $link_loaded->getTranslation($language_id)->content_translation_source->value) {
              if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
                drush_log('You can not delete the origin "' . $language_id . '" and keep the translation from link id "' . $mid . '"', 'warning');
              }
            } else {
              $link_loaded->removeTranslation($language_id);
              $link_loaded->save();
            }
          }
        }
      }

      if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
        drush_log('Deleted menu links that were not in config', 'ok');
      }
      StructureSyncHelper::logMessage('Deleted menu links that were not in config');
    }
  }

  /**
   * Function to fully import the menu links.
   *
   * Basically a safe import with update actions for already existing menu
   * links.
   */
  public static function importMenuLinksFull($menus, &$context)
  {
    $uuidsInConfig = [];
    foreach ($menus as $menuLink) {
      foreach ($menuLink as $language_id => $link) {
        $uuidsInConfig[] = $link['uuid'];
      }
    }
    // Remove duplicates
    $uuidsInConfig = array_unique($uuidsInConfig);

    $entities = [];
    if (!empty($uuidsInConfig)) {
      $query = StructureSyncHelper::getEntityQuery('menu_link_content');
      $query->condition('uuid', $uuidsInConfig, 'IN');
      $ids = $query->execute();
      $controller = StructureSyncHelper::getEntityManager()
        ->getStorage('menu_link_content');
      $entities = $controller->loadMultiple($ids);
    }

    $parents = array_column($menus, 'parent');
    foreach ($parents as &$parent) {
      if (!is_null($parent)) {
        if (($pos = strpos($parent, ":")) !== FALSE) {
          $parent = substr($parent, $pos + 1);
        } else {
          $parent = NULL;
        }
      }
    }

    $idsDone = [];
    $idsLeft = [];
    $firstRun = TRUE;
    $context['sandbox']['max'] = count($menus);
    $context['sandbox']['progress'] = 0;
    while ($firstRun || count($idsLeft) > 0) {
      foreach ($menus as $menuLink) {
        foreach ($menuLink as $language_id => $link) {
          $query = StructureSyncHelper::getEntityQuery('menu_link_content');
          $query->condition('uuid', $link['uuid']);
          $query->condition('langcode', $language_id);
          $ids = $query->execute();

          $currentParent = $link['parent'];
          if (!is_null($currentParent)) {
            if (($pos = strpos($currentParent, ":")) !== FALSE) {
              $currentParent = substr($currentParent, $pos + 1);
            }
          }
        }
        if (!in_array($link['uuid'], $idsDone)
          && ($link['parent'] === NULL
            || !in_array($link['parent'], $parents)
            || in_array($currentParent, $idsDone))
        ) {
          if (count($ids) <= 0) {
            $query = StructureSyncHelper::getEntityQuery('menu_link_content');
            $query->condition('uuid', $link['uuid']);
            // Add translation
            if (count($query->execute()) >= 1) {
              MenuLinkContent::load($link['id'])->addTranslation($language_id, $link)->save();
              if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
                drush_log('Imported link translation (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '"', 'ok');
              }
              StructureSyncHelper::logMessage('Imported link translation (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '"');
            } // Create a new term
            else {
              MenuLinkContent::create($link)->save();
              if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
                drush_log('Imported link "' . $link['title'] . '" into "' . $link['menu_name'] . '"', 'ok');
              }
              StructureSyncHelper::logMessage('Imported link "' . $link['title'] . '" into "' . $link['menu_name'] . '"');
            }
          } // Update links
          else {
            foreach ($entities as $entity) {
              if ($link['uuid'] === $entity->uuid()) {
                $link_loaded = MenuLinkContent::load($entity->id());
                foreach ($link as $field_name => $field_value) {
                  if ($link_loaded->get($field_name)->getFieldDefinition()->isTranslatable()) {
                    // Update link field translation
                    $link_loaded->getTranslation($language_id)->{$field_name}->setValue($field_value);
                  } else {
                    // Update link field
                    $link_loaded->{$field_name}->setValue($field_value);
                  }
                }
                // Save link
                $link_loaded->save();

                if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
                  drush_log('Updated link (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '"', 'ok');
                }
                StructureSyncHelper::logMessage('Updated link (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '"');

                break;
              } else {
                $idsLeft[$link['uuid']] = $link['uuid'];
              }
            }
          }

          $idsDone[] = $link['uuid'];
          $context['sandbox']['progress']++;
          if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
            $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
          }

        }
      }

      $firstRun = FALSE;
    }

    $context['finished'] = 1;
  }

  /**
   * Function to import menu links safe (only adding what isn't already there).
   */
  public static function importMenuLinksSafe($menus, &$context)
  {
    $menusFiltered = $menus;

    $entities = StructureSyncHelper::getEntityManager()
      ->getStorage('menu_link_content')
      ->loadMultiple();

    foreach ($entities as $key => $entity) {
      foreach ($menusFiltered as $key => $menuLink) {
        foreach ($menuLink as $language_id => $link) {
          if ($entity->uuid() === $link['uuid']) {
            if ($entity->hasTranslation($language_id)) {
              unset($menusFiltered[$key][$language_id]);
            }
          }
        }
      }
    }
    // Import new links and translation links
    if (count($menusFiltered) > 0 && count($menusFiltered[0]) > 0) {
      \Drupal\structure_sync\Controller\MenuLinksController::importMenuLinksForce($menusFiltered, $context);
    } else {
      if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
        drush_log('Not found new links to import', 'ok');
      }
      StructureSyncHelper::logMessage('Not found new links to import');
    }
  }

  /**
   * Function to delete all menu links.
   */
  public
  static function deleteMenuLinks(&$context)
  {
    $entities = StructureSyncHelper::getEntityManager()
      ->getStorage('menu_link_content')
      ->loadMultiple();
    StructureSyncHelper::getEntityManager()
      ->getStorage('menu_link_content')
      ->delete($entities);

    if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
      drush_log('Deleted all (content) menu links', 'ok');
    }
    StructureSyncHelper::logMessage('Deleted all (content) menu links');
  }

  /**
   * Function to import (create) all menu links that need to be imported.
   */
  public
  static function importMenuLinksForce($menus, &$context)
  {
    foreach ($menus as $menuLink) {

      foreach ($menuLink as $language_id => $link) {
        if ($link['content_translation_source'] === 'und') {
          MenuLinkContent::create($link)->save();
          if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
            drush_log('Imported link "' . $link['title'] . '" into "' . $link['menu_name'] . '" menu', 'ok');
          }
          StructureSyncHelper::logMessage('Imported link "' . $link['title'] . '" into "' . $link['menu_name'] . '" menu');
        } else {
          MenuLinkContent::load($link['id'])->addTranslation($language_id, $link)->save();
          if (array_key_exists('drush', $context) && $context['drush'] === TRUE) {
            drush_log('Imported link translation (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '" menu', 'ok');
          }
          StructureSyncHelper::logMessage('Imported link translation (' . $language_id . ') "' . $link['title'] . '" into "' . $link['menu_name'] . '" menu');
        }
      }
    }
  }

  /**
   * Function that signals that the import of menu links has finished.
   */
  public
  static function menuLinksImportFinishedCallback($success, $results, $operations)
  {
    StructureSyncHelper::logMessage('Flushing all caches');

    drupal_flush_all_caches();

    StructureSyncHelper::logMessage('Successfully flushed caches');

    StructureSyncHelper::logMessage('Successfully imported menu links');

    drupal_set_message(t('Successfully imported menu links'));
  }

}
