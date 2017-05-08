<?php
/**
 * 
 * @author Daniel Kesler
 * @version 0.10.0
 * @license https://opensource.org/licenses/GPL-3.0
 * 
 */
 
defined('BASEPATH') OR exit('No direct script access allowed');
 
class Plugin_fab_diagnostic extends FAB_Controller {

	function __construct()
	{
		parent::__construct();
		if(!$this->input->is_cli_request()){ //avoid this form command line
			//check if there's a running task
			//load libraries, models, helpers
			$this->load->model('Tasks', 'tasks');
			$this->runningTask = $this->tasks->getRunning();
		}
	}

	private function getTestCases($test)
	{
		$this->load->helper('plugin_helper');
		$test_case_file = plugin_path() . '/assets/json/test_cases/' . $test . '.json';
		
		if(file_exists($test_case_file))
		{
			$data = json_decode( file_get_contents($test_case_file), 1);
			return $data;
		}
		
		return array();
	}

	public function index($tab = '')
	{
		$this->load->library('smart');
		$this->load->helper('form');
		$this->load->helper('fabtotum_helper');
		$this->load->helper('plugin_helper');
		
		$data = array();
		
		$widgetOptions = array(
			'sortable'     => false, 'fullscreenbutton' => true,  'refreshbutton' => false, 'togglebutton' => false,
			'deletebutton' => false, 'editbutton'       => false, 'colorbutton'   => false, 'collapsed'    => false
		);
		
		// Tab php filenames are generated by 'key'+'_tab'
		// Tab content id is generated by 'key'+'-tab'
		$tabs = array(
			'selftests' => _('Self-tests'),
			'network' => _('Network'),
			'firmware' => _('Firmware'),
			'sensors' => _('Sensors'),
			'fabui' => _('FabUI'),
			'os' => _('OS')
		);
		
		$headerToolbar = '<ul class="nav nav-tabs pull-right">';
		$tabs_content  = '';
		
		if($tab == '')
			$tab = 'selftests';
		
		foreach($tabs as $key => $title)
		{
			$is_active = ($tab == $key);
			$headerToolbar .= '<li '.($is_active?'class="active"':'').'><a data-toggle="tab" href="#'.$key.'-tab"> '._($title).'</a></li>';
			$tab_content = '';
			
			$test_cases = $this->getTestCases($key);
			
			foreach($test_cases as $tc_key => $tc_info)
			{
				$run_file = '/tmp/fabui/testcase/'.$key.'_'.$tc_info['test'].'/run_log.json';
				$has_run = file_exists($run_file);
				$run_content = '';
				if($has_run)
				{
					$run_info = json_decode( file_get_contents($run_file), 1 );
					if(file_exists($run_info['log']))
						$run_info['log'] = file_get_contents($run_info['log']);
					else
						$run_info['log'] = '';
						
					if(file_exists($run_info['result']))
						$data['result'] = file_get_contents($run_info['result']);
					else
						$data['result'] = '';
					
					if($run_info['test'] == 'passed')
					{
						$run_content ='<button class="btn btn-success test-action" data-subsystem="'.$key.'" data-test-case="'.$tc_info['test'].'" data-action="show-log"><i class="fa fa-check-circle" aria-hidden="true"></i> Passed</button>';
					}
					else
					{
						$run_content ='<button class="btn btn-danger test-action" data-subsystem="'.$key.'" data-test-case="'.$tc_info['test'].'" data-action="show-log"><i class="fa fa-times-circle" aria-hidden="true"></i> Failed</button>';
					}
				}
				$tab_content .= '
					<div class="row">
						<div class="col col-md-6">
							<dl class="dl-horizontal">
								<dt>Test:</dt>
								<dd>'.$tc_info['title'].'</dd>
								<dt>Description:</dt>
								<dd>'.$tc_info['desc'].'</dd>
							</dl>
						</div>
						<div class="col col-md-6">
							<button class="btn test-action" data-subsystem="'.$key.'" data-test-case="'.$tc_info['test'].'" data-action="run"><i class="fa fa-play" aria-hidden="true"></i></button>
							<span id="'.$key.'_'.$tc_info['test'].'-result">'.$run_content.'</span>
						</div>
					</div>
					<hr class="simple">';
			}
			
			$tab_data = array();
			$tab_data['tab_active'] = $is_active;
			$tab_data['tab_id'] = $key;
			$tab_data['tab_content'] = $tab_content;
			$tabs_content .= $this->load->view(plugin_url($key.'_tab'), $tab_data, true );
		}
		
		$headerToolbar .= '</ul>';
		
		$data['tabs_content'] = $tabs_content;
		
		$widgeFooterButtons = '';

		$widget         = $this->smart->create_widget($widgetOptions);
		$widget->id     = 'main-widget-head-installation';
		$widget->header = array('icon' => 'fa fa-heartbeat', "title" => "<h2>" , _("Diagnostic tools") . "</h2>", 'toolbar' => $headerToolbar);
		$widget->body   = array('content' => $this->load->view(plugin_url('main_widget'), $data, true ), 'class'=>'no-padding', 'footer'=>$widgeFooterButtons);

		$this->addJsInLine($this->load->view(plugin_url('js'), $data, true));
		$this->addJSFile( plugin_assets_url('js/ansi_up.js'));
		$this->content = $widget->print_html(true);
		$this->view();
	}
	
	public function runTestCase($subsystem, $test_case)
	{
		$this->load->helper('plugin_helper');
		$this->load->helper('fabtotum_helper');
		$this->config->load('fabtotum');
		$extPath = plugin_path() . '/scripts/bash/';
		$result = shell_exec('sudo sh '.$extPath . $subsystem . '_' . $test_case . '.sh');
		$data = json_decode($result, 1);
		
		if($data)
		{
			if(file_exists($data['log']))
				$data['log'] = file_get_contents($data['log']);
			else
				$data['log'] = '';
				
			if(file_exists($data['result']))
				$data['result'] = file_get_contents($data['result']);
			else
				$data['result'] = '';
				
		}
		else
		{
			$logfile = '/tmp/fabui/testcase/'.$subsystem .'_'.$test_case.'/testcase.log';
			$runfile = '/tmp/fabui/testcase/'.$subsystem .'_'.$test_case.'/run_log.json';
			
			file_put_contents('/tmp/fabui/testcase.log', '== Test case script failure =='.PHP_EOL);
			
			$data = array(
				'log' => $logfile,
				'test' => 'failed',
				'result' => ''
			);
			
			file_put_contents('/tmp/fabui/run_log.json', json_encode($data));
			
			$result = shell_exec('sudo sh '.$extPath . $subsystem . '_' . $test_case . '.sh >> /tmp/fabui/testcase.log 2>&1');
			
			shell_exec('sudo mv /tmp/fabui/testcase.log '.$logfile);
			shell_exec('sudo mv /tmp/fabui/run_log.json '.$runfile);
		}
		
		$this->output->set_content_type('application/json')->set_output(json_encode($data));
	}
	
	public function getTestCaseLog($subsystem, $test_case)
	{
		$logfile = '/tmp/fabui/testcase/'.$subsystem .'_'.$test_case.'/testcase.log';
		if(file_exists($logfile))
		{
			echo file_get_contents($logfile);
		}
	}

 }
 
?>
