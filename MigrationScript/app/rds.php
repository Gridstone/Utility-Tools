<?php

	require_once 'aws/vendor/autoload.php';
	require_once 'libs/kLogger/src/KLogger.php';
	
	use Aws\Rds\RdsClient;
	
	/**
	 * RDS class.
	 * 
	 * @author RIPE
	 *
	 * @extends AwsService
	 */
	class RDS extends AwsService
	{
		
		// AWS classes
		var $source_rds_client      = '';
		var $destination_rds_client = '';
		var $source_region = '';
		var $dest_region = '';
		
		// Log
		var $log = '';
	
		// -----------------------------------------------------
		
 		/**
 		 * __construct function.
 		 * 
 		 * @access public
 		 * @return void
 		 */
 		function __construct()
		{
			parent::__construct();
			
			$this->log = KLogger::instance(dirname(__FILE__)."/log", KLogger::DEBUG);
			
			$this->log->logInfo("Initialising source and destination rdsclient.");
			
			$creds = json_decode(file_get_contents("credentials/cred.json"), true);
			
			try
			{
				// Source Init
				$this->source_rds_client = RdsClient::factory(array(
				    'key'    => $creds["source"]["access_key"],
				    'secret' => $creds["source"]["secret_key"],
				    'region' => $creds["source"]["region"]
				));
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			try
			{
				// Source Destination
				$this->destination_rds_client = RdsClient::factory(array(
				    'key'    => $creds["destination"]["access_key"],
				    'secret' => $creds["destination"]["secret_key"],
				    'region' => $creds["destination"]["region"]
				));
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception Create Client::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Source and Destination Client ");
			
			$this->source_region = $creds["source"]["region"];
			$this->dest_region = $creds["destination"]["region"];
		}
		
		// --------------------------------------------------------------
		
		/**
		 * replicateRdss function.
		 * 
		 * @access public
		 * @param mixed $subnetAssoc
		 * @param mixed $securityGroupAssoc
		 * @return void
		 */
		function replicateRdss($subnetAssoc, $securityGroupAssoc)
		{
			$this->log->logInfo("RDS replication has started!");
			
			try
			{
				$result = $this->source_rds_client->describeDBInstances(array());
				$dbInstances = $result->get('DBInstances');

				$this->report($dbInstances);

				foreach($dbInstances as $dbinstance)
				{
					if(!isset($dbinstance['StatusInfos']))
					{
						// --------------------------------------------------------------
						// Prepare Dependencies 
						echo "<pre>";
						echo print_r($dbinstance,true);
						
						$masterUserPassword   = $this->generateRandomString(20);
						$vpcSecurityGroupIds  = array();
						$dbSubnetGroup        = '';
						$dbParameterGroupName = '';
						$dbOptionGroupName    = '';
						 
						foreach($dbinstance['VpcSecurityGroups'] as $vpcGroup) {
							$vpcSecurityGroupIds[] = $securityGroupAssoc[$vpcGroup['VpcSecurityGroupId']];
						}
						
						$this->log->logInfo("SecurityGroup to be added to sb instance: ", $vpcSecurityGroupIds);
						
						// Create DB SubnetGroup
						$sourceDBSubnetGroupName = $this->source_rds_client->describeDBSubnetGroups(array());
						$sDBSubnetGroupsNames = $sourceDBSubnetGroupName->get('DBSubnetGroups');
						foreach($sDBSubnetGroupsNames as $sDBSubnetGroupsName)
						{
							$subnets = array();
							foreach($sDBSubnetGroupsName['Subnets'] as $subnet){
								$subnets[] = $subnetAssoc[$subnet['SubnetIdentifier']];
							}
							$dbSubnetGroupNameResult = $this->destination_rds_client->createDBSubnetGroup(array(
							    'DBSubnetGroupName' => $sDBSubnetGroupsName['DBSubnetGroupName'],
							    'DBSubnetGroupDescription' => $sDBSubnetGroupsName['DBSubnetGroupDescription'],
							    'SubnetIds' => $subnets
							));
							$name = $dbSubnetGroupNameResult->get('DBSubnetGroup');
							$dbSubnetGroup = $sDBSubnetGroupsName['DBSubnetGroupName'];
							
							$this->log->logInfo("Subnet group created with name: ".$dbSubnetGroup);
						}
						
						// Create DBParameterGroupName
						$sourceParameterGroupNames = $this->source_rds_client->describeDBParameterGroups(array());
						$sParamGroups = $sourceParameterGroupNames->get('DBParameterGroups');
						foreach($sParamGroups as $sourceParameterGroupName) {
							if(strpos($sourceParameterGroupName['DBParameterGroupName'], 'default') === false)
							{
								$resultDbCreateParamGroupName = $this->destination_rds_client->createDBParameterGroup(array(
								    'DBParameterGroupName' => $sourceParameterGroupName['DBParameterGroupName'],
								    'DBParameterGroupFamily' => $sourceParameterGroupName['DBParameterGroupFamily'],
								    'Description' => $sourceParameterGroupName['Description']
								));
								$name = $resultDbCreateParamGroupName->get('DBParameterGroup');
								$dbParameterGroupName = $sourceParameterGroupName['DBParameterGroupName'];
								
								$this->log->logInfo("Parameter group created with name: ".$dbParameterGroupName);
							}
						}
						
						// Create DBOptionGroupName
						$sourceDBOptionGroupName = $this->source_rds_client->describeOptionGroups(array());
						$optionGroupList = $sourceDBOptionGroupName->get('OptionGroupsList');
						
						foreach($optionGroupList as $optionGroup) {
							if(strpos($optionGroup['OptionGroupName'], 'default') === false)
							{
								$resultDbCreateOptionGroupName = $this->destination_rds_client->createOptionGroup(array(
								    'OptionGroupName' => $optionGroup['OptionGroupName'],
								    'EngineName' => $optionGroup['EngineName'],
								    'MajorEngineVersion' => $optionGroup['MajorEngineVersion'],
								    'OptionGroupDescription' => $optionGroup['OptionGroupDescription'],
								));
								$name = $resultDbCreateOptionGroupName->get('OptionGroup');
								$dbOptionGroupName = $optionGroup['OptionGroupName'];
								
								$this->log->logInfo("Option group created with name: ".$dbOptionGroupName);
							}
						}
						
						// --------------------------------------------------------------
						
						$data = array(
						    'DBName'                      => $dbinstance['DBName'],
						    'DBInstanceIdentifier'        => $dbinstance['DBInstanceIdentifier'],
						    'AllocatedStorage'            => $dbinstance['AllocatedStorage'],
						    'DBInstanceClass'             => $dbinstance['DBInstanceClass'],
						    'Engine'                      => $dbinstance['Engine'],
						    'MasterUsername'              => $dbinstance['MasterUsername'],
						    'MasterUserPassword'          => $masterUserPassword,
						    'DBSubnetGroupName'           => $dbSubnetGroup,
						    'PreferredMaintenanceWindow'  => $dbinstance['PreferredMaintenanceWindow'],
						    'DBParameterGroupName'        => $dbParameterGroupName,
						    'VpcSecurityGroupIds'         => $vpcSecurityGroupIds,
						    'BackupRetentionPeriod'       => $dbinstance['BackupRetentionPeriod'],
						    'PreferredBackupWindow'       => $dbinstance['PreferredBackupWindow'],
						    'Port'                        => $dbinstance['Endpoint']['Port'],
						    'MultiAZ'                     => $dbinstance['MultiAZ'],
						    'EngineVersion'               => $dbinstance['EngineVersion'],
						    'AutoMinorVersionUpgrade'     => $dbinstance['AutoMinorVersionUpgrade'],
						    'LicenseModel'                => $dbinstance['LicenseModel'],
						    'PubliclyAccessible'          => false,
						    'OptionGroupName'             => $dbOptionGroupName
						);
						
						$this->log->logInfo("passed data while creating the instance: ", $data);
						
						$result = $this->destination_rds_client->createDBInstance($data);
					}
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("RDS replication has finished!");
		}
		
	}

?>