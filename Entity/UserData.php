<?php namespace Hampel\ApprovalQueuePlus\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class UserData extends Entity
{
	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_aqp_user_data';
		$structure->shortName = 'Hampel\ApprovalQueuePlus:UserData';
		$structure->primaryKey = 'user_id';
		$structure->columns = [
			'user_id' => ['type' => self::UINT, 'required' => true],
			'user_agent' => ['type' => self::STR, 'required' => true],
            'iso_code' => ['type' => self::STR, 'maxLength' => 2, 'default' => ''],
            'cf_location' => ['type' => self::JSON_ARRAY, 'default' => []]
		];

		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			]
		];

		return $structure;
	}
}
