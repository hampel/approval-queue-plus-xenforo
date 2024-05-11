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

    // upgrade to 3.5.0
    public function upgrade3050070Step1(array $stepParams = [])
    {
        if ($this->schemaManager()->tableExists('xf_user_agent'))
        {
            $this->schemaManager()->renameTable('xf_user_agent', 'xf_aqp_user_data');
        }
        elseif ($this->schemaManager()->tableExists('xf_aqp_user_agent'))
        {
            $this->schemaManager()->renameTable('xf_aqp_user_agent', 'xf_aqp_user_data');
            $this->schemaManager()->alterTable('xf_aqp_user_data', function(Alter $table)
            {
                $table->addColumn('iso_code', 'varchar', 2)->setDefault('')->after('user_agent');
                $table->addColumn('cf_location', 'mediumblob')->after('iso_code');
            });
        }
        else
        {
            $this->createTables();
        }
    }

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_aqp_user_data');
	}

	protected function createTables()
	{
		$this->schemaManager()->createTable('xf_aqp_user_data', function(Create $table)
		{
			$table->addColumn('user_id', 'int');
			$table->addColumn('user_agent', 'text');
            $table->addColumn('iso_code', 'varchar', 2);
            $table->addColumn('cf_location', 'mediumblob');
			$table->addPrimaryKey('user_id');
		});
	}
}