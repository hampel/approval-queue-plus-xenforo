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

    // upgrade to 3.4.0
    public function upgrade3040070Step1(array $stepParams = [])
    {
        if ($this->schemaManager()->tableExists('xf_user_agent'))
        {
            $this->schemaManager()->renameTable('xf_user_agent', 'xf_aqp_user_agent');
        }
        else
        {
            $this->createTables();
        }
    }

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_aqp_user_agent');
	}

	protected function createTables()
	{
		$this->schemaManager()->createTable('xf_aqp_user_agent', function(Create $table)
		{
			$table->addColumn('user_id', 'int');
			$table->addColumn('user_agent', 'text');
			$table->addPrimaryKey('user_id');
		});
	}
}