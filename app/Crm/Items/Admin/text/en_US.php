<?php
declare(strict_types=1);

// The own words of the panel Items, one file for each interface language (see Admin::text()): mixed into the fills of the
// workbench while the module is active, never into the text files of the project.
return [

	'[[/_admin/crm-items/nav]]' => 'Items',
	'[[/_admin/crm-items/label/search]]' => 'Search',
	'[[/_admin/crm-items/label/find]]' => 'Search',
	'[[/_admin/crm-items/label/new]]' => 'New item',
	'[[/_admin/crm-items/label/back]]' => 'Back to the list',
	'[[/_admin/crm-items/label/save]]' => 'Save',
	'[[/_admin/crm-items/label/deactivate]]' => 'Deactivate',
	'[[/_admin/crm-items/label/activate]]' => 'Activate',
	'[[/_admin/crm-items/label/up]]' => 'Move up',
	'[[/_admin/crm-items/label/down]]' => 'Move down',
	'[[/_admin/crm-items/field/title]]' => 'Title',
	'[[/_admin/crm-items/field/description]]' => 'Description',
	'[[/_admin/crm-items/field/unit]]' => 'Unit',
	'[[/_admin/crm-items/field/price]]' => 'Net price',
	'[[/_admin/crm-items/field/vat-rate]]' => 'VAT',
	'[[/_admin/crm-items/empty/items]]' => 'No items yet. Add the first.',
	'[[/_admin/crm-items/empty/found]]' => 'No item matches your search.',
	'[[/_admin/crm-items/state/inactive]]' => 'inactive',
	'[[/_admin/crm-items/error/load]]' => 'The items could not be loaded.',
	'[[/_admin/crm-items/error/save]]' => 'The item could not be saved.',
	'[[/_admin/crm-items/error/active]]' => 'The item could not be switched.',
	'[[/_admin/crm-items/error/order]]' => 'The order could not be saved.',

	'[[/_admin/error/crm_items_database]]' => 'The database refused the request.',
	'[[/_admin/error/crm_items_amount]]' => 'The price is not a number, for example 1,234.56.',
	'[[/_admin/error/crm_items_empty]]' => 'An item needs a title.',
	'[[/_admin/error/crm_items_format]]' => 'The value is no text.',
	'[[/_admin/error/crm_items_id]]' => 'The id of the item is not valid.',
	'[[/_admin/error/crm_items_long]]' => 'The value may have at most %s characters.',
	'[[/_admin/error/crm_items_rate]]' => 'The rate is none of the rates in the settings.',
	'[[/_admin/error/crm_items_unknown]]' => 'There is no such item.',
];
