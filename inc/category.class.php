<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @copyright Copyright © 2011 - 2020 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginFormcreatorCategory extends CommonTreeDropdown
{
   // Activate translation on GLPI 0.85
   var $can_be_translated = true;

   static function canView() {
      return true;
   }

   public static function getTypeName($nb = 1) {
      return _n('Form category', 'Form categories', $nb, 'formcreator');
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      $env       = new self;
      $found_env = $env->find([static::getForeignKeyField() => $item->getID()]);
      $nb        = count($found_env);
      return self::createTabEntry(self::getTypeName($nb), $nb);
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType()==__CLASS__) {
         $item->showChildren();
      }
   }

   public function getAdditionalFields() {
      return [
         [
            'name'      => KnowbaseItemCategory::getForeignKeyField(),
            'type'      => 'dropdownValue',
            'label'     => __('Knowbase category', 'formcreator'),
            'list'      => false
         ],
         [
            'name'      => $this->getForeignKeyField(),
            'type'      => 'parent',
            'label'     => __('As child of'),
            'list'      => false
         ]
      ];
   }

   /**
    * @param integer $rootId id of the subtree root
    * @param boolean $helpdeskHome
    * @return array Tree of form categories as nested array
    */
   public static function getCategoryTree($rootId = 0, $helpdeskHome = false) {
      global $DB;

      $cat_table  = PluginFormcreatorCategory::getTable();
      $form_table = PluginFormcreatorForm::getTable();
      $table_fp   = PluginFormcreatorForm_Profile::getTable();

      $query_faqs = KnowbaseItem::getListRequest([
         'faq'      => '1',
         'contains' => ''
      ]);
      if (version_compare(GLPI_VERSION, "9.5") >= 0) {
         // GLPI 9.5 returns an array
         $subQuery = new DBMysqlIterator($DB);
         $subQuery->buildQuery($query_faqs);
         $query_faqs = $subQuery->getSQL();
      }

      // Selects categories containing forms or sub-categories
      $categoryFk = self::getForeignKeyField();
      $dbUtils = new DbUtils();
      $count1 = new QuerySubQuery([
         'COUNT' => 'count',
         'FROM' => $form_table,
         'WHERE' => [
            'is_active'    => '1',
            'is_deleted'   => '0',
            "$form_table.plugin_formcreator_categories_id" => new QueryExpression("$cat_table.id"),
            'language' => [$_SESSION['glpilanguage'], '', '0', null],
            'OR' => [
               'access_rights' => ['!=', PluginFormcreatorForm::ACCESS_RESTRICTED],
               'id' => new QuerySubQuery([
                  'SELECT' => 'plugin_formcreator_forms_id',
                  'FROM' => $table_fp,
                  'WHERE' => ['profiles_id' => $_SESSION['glpiactiveprofile']['id']],
               ])
            ]
         ]
         + ($helpdeskHome ? ['helpdesk_home' => '1']: [])
         + $dbUtils->getEntitiesRestrictCriteria($form_table, "", "", true, false),
      ]);
      $count2 = new QuerySubQuery([
         'COUNT' => 'count',
         'FROM' => (new QueryExpression("($query_faqs) as faqs")),
         'WHERE' => [
            [(new QueryExpression("faqs.knowbaseitemcategories_id = $cat_table.knowbaseitemcategories_id"))],
            ["faqs.knowbaseitemcategories_id" => ['!=', '0'],],
         ]
      ]);
      $request = [
         'SELECT' => [
            'id',
            'name',
            "$categoryFk as parent",
            'level',
            new QueryExpression(
               $count1->getQuery() . " + " . $count2->getQuery() . " as items_count"
            ),
         ],
         'FROM' => $cat_table,
         'ORDER' => ["level DESC", "name DESC"],
      ];
      $result = $DB->request($request);

      $categories = [];
      foreach($result as $category) {
         $category['name'] = Dropdown::getDropdownName($cat_table, $category['id'], 0, true, false);
         $categories[$category['id']] = $category;
      }

      // Remove categories that have no items and no children
      // Requires category list to be sorted by level DESC
      foreach ($categories as $index => $category) {
         $children = array_filter(
            $categories,
            function ($element) use ($category) {
               return $category['id'] == $element['parent'];
            }
         );
         if (empty($children) && 0 == $category['items_count']) {
            unset($categories[$index]);
            continue;
         }
         $categories[$index]['subcategories'] = [];
      }

      // Create root node
      $nodes = [
         'name'            => '',
         'id'              => 0,
         'parent'          => 0,
         'subcategories'   => [],
      ];
      $flat = [
         0 => &$nodes,
      ];

      // Build from root node to leaves
      $categories = array_reverse($categories);
      foreach ($categories as $item) {
         $flat[$item['id']] = $item;
         $flat[$item['parent']]['subcategories'][] = &$flat[$item['id']];
      }

      return $nodes;
   }

   public static function  getAvailableCategories($helpdeskHome = 1) {
      global $DB;

      // Define tables
      $cat_table       = PluginFormcreatorCategory::getTable();
      $categoryFk      = PluginFormcreatorCategory::getForeignKeyField();
      $formTable       = PluginFormcreatorForm::getTable();
      $formRestriction = PluginFormcreatorForm::getFormRestrictionCriterias($formTable);

      $formRestriction["$formTable.helpdesk_home"] = $helpdeskHome;

      $result = $DB->request([
         'SELECT' => [
            $cat_table => [
               'name', 'id'
            ]
         ],
         'FROM' => $cat_table,
         'INNER JOIN' => [
            $formTable => [
               'FKEY' => [
                  $cat_table => 'id',
                  $formTable => $categoryFk
               ]
            ]
         ],
         'WHERE' => PluginFormcreatorForm::getFormRestrictionCriterias($formTable),
         'GROUPBY' => [
            "$cat_table.id"
         ]
      ]);

      return $result;
   }

   public static function getAvailableCategoriesCriterias() {

   }
}
