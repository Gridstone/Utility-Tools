###############################################################
#															  #
# - Documentation For AWS infrastructure replication script.  #
#															  #
# - Files:													  #
#															  #
#		- aws-copy-across-region {ROOT}						  #
#			- /app											  #
#				- AwsService.php {Service Class}			  #
# 				- elb.php {ELB related replicator code}		  #
#				- /log										  #
#               - rds.php {RDS related replicator code}		  #
#               - vpc.php {VPC related replicator code}		  #
#			- /aws											  #
#           	- {AWS Library Code}						  #	
#           - /credentials									  #
#				- cred.json {Script configuration file.}      #
#           - /doc											  #
#				- readme.html								  #	
#           - /keypairs										  #
#				- {.pem files}								  #
#           - /libs											  #
#				- {KLogger library code}					  #
#           - /log											  #
#				- {script creates log files}				  #
#           - replicate.php									  #
#           - /report										  #
#           - /rollbackdata									  #
#				- {rollbackdata.json}						  #
#															  #
#	@author RIPE											  #
#															  #
###############################################################



#################################################################
#																#
#	Prerequisite:												#
#	=============										        #
#	                                                            # 
# RECOMMENDED IAM USER POLICY FOR SOURCE AND DESTINATION REGION #
#                                                               #
# Please check the policy before you apply it to your account.  #
#                                                               #
#################################################################

{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "rds:CreateDBSubnetGroup",
        "rds:CreateDBParameterGroup",
        "rds:CreateOptionGroup",
        "rds:CreateDBInstance",
        "ec2:AllocateAddress",
        "ec2:CopyImage",
        "ec2:AssociateAddress",
        "ec2:TerminateInstances",
        "ec2:ModifyInstanceAttribute",
        "ec2:AssociateDhcpOptions",
        "ec2:AssociateRouteTable",
        "ec2:AttachInternetGateway",
        "ec2:AttachVpnGateway",
        "ec2:AuthorizeSecurityGroupEgress",
        "ec2:AuthorizeSecurityGroupIngress",
        "ec2:CreateCustomerGateway",
        "ec2:CreateDhcpOptions",
        "ec2:CreateInternetGateway",
        "ec2:ModifyInstanceAttribute",
        "ec2:CreateNetworkAcl",
        "ec2:CreateNetworkAclEntry",
        "ec2:CreateRoute",
        "ec2:CreateRouteTable",
        "ec2:CreateSecurityGroup",
        "ec2:CreateSubnet",
        "ec2:CreateVpc",
        "ec2:CreateVpnConnection",
        "ec2:CreateVpnConnectionRoute",
        "ec2:CreateVpnGateway",
        "ec2:RunInstances",
        "ec2:CreateTags",
        "ec2:DescribeAddresses",
        "ec2:DescribeAvailabilityZones",
        "ec2:DescribeCustomerGateways",
        "ec2:DescribeDhcpOptions",
        "ec2:DescribeInstances",
        "ec2:DescribeInternetGateways",
        "ec2:DescribeKeyPairs",
        "ec2:ImportKeyPair",
        "ec2:CreateKeyPair",
        "ec2:DescribeNetworkAcls",
        "ec2:DescribeNetworkInterfaces",
        "ec2:DescribeRouteTables",
        "ec2:DescribeSecurityGroups",
        "ec2:DescribeSubnets",
        "ec2:DescribeImages",
        "ec2:DescribeVpcAttribute",
        "ec2:DescribeVpcs",
        "ec2:DescribeVpnConnections",
        "ec2:DescribeVpnGateways",
        "ec2:ModifyVpcAttribute",
        "ec2:ReplaceNetworkAclAssociation",
        "ec2:ReplaceNetworkAclEntry",
        "ec2:ReplaceRouteTableAssociation",
        "ec2:WaitUntilInstanceRunning",
        "elasticloadbalancing:CreateLoadBalancer",
        "elasticloadbalancing:DescribeLoadBalancers"
      ],
      "Resource": "*"
    }
  ]
}

How to use:
===========

1). Setup credentials.

	- File location: aws-copy-across-region/credentials/cred.json
	
	{
		"scriptVersion": {
			"no": "0.1"	
		},
		"accountId": {
			"id": "destination-aws-account-id"	
		},
		"source": {
			"access_key": "",
			"secret_key": "",
			"region" : "ap-southeast-2"
		},
		"destination": {
			"access_key": "",
			"secret_key": "",
			"region" : "ap-southeast-1"
		}
	}
	
	- {{destination-aws-account-id}} = This will be the account ID where you intended the script to create the source replica.
	
	- {{source}}                     = {access_key} = access_key for the the IAM User in source account.
									   {secret_key} = secret_key for the the IAM User in source account.
									   
	- {{destination}}                = {access_key} = access_key for the the IAM User in destination account.
									   {secret_key} = secret_key for the the IAM User in destination account.
									   
	- {{region}}                     = Select the source region where infrastructure is available.
									   Choose destination region where infrastructure needs to copied.
									   
	Notes: List of regions can be found on AWS website.
	
	
2). Collect .pem keys of source infrastructure instances.
	
	- In order to run the replication. It requires these keys to be available.
	
	- Why? 
	
		- When script spins up Bastion and NAT server it needs to provide public key.
		  So that once the server is up and running we can access it. If you want to 
		  create new keys for destination region than create new PEM keys with same 
		  naming convention as the source region and script will configure destination 
		  region with new keys.
		  
3). Open MyAccount section of destination acount. Copy the account ID remove all
	hyphens or spaces from it and paste into our cred.json
	
4). Run your local apache server and set aws-copy-across-region folder as ROOT.

5) Open browser and hit http://localhost/replicate.php

	5.1) The script creates a log which can be monitored by tail -f inside terminal.
	
		- aws-copy-across-region/log/log-{mm-dd-YYY}.log

6) After Math:

	6.1) Script also creates other documentation such as the rollbackdata report.
		 Rollback data contains key value json which maps the source resource-id
		 with recently created destination resource-id (e.g. where resource-id is
		 the amazon id of Instance, Routing Table, VPC, Internet Gateway ID etc.)

		  