<?php namespace Hampel\ApprovalQueuePlus;

use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerUpgradeTrait;

	public function install(array $stepParams = [])
	{
		$this->createTables();
	}

	// upgrade to 3.2.0
	public function upgrade3020070Step1(array $stepParams = [])
	{
		$this->createTables();
	}

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_user_agent');
	}

	protected function createTables()
	{
		$this->schemaManager()->createTable('xf_user_agent', function(Create $table)
		{
			$table->addColumn('user_id', 'int');
			$table->addColumn('user_agent', 'text');
			$table->addPrimaryKey('user_id');
		});
	}
}