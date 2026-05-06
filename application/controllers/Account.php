<?php
defined('BASEPATH') OR exit('No direct script access allowed');


class Account extends CI_Controller {

	public function login() {
        if ($this->session->userdata("user")){
            redirect('Dashboard');
        }
		$this->load->view('v_login');
	}

	public function validate() {
		$data = $this->input->post();

		$res = $this->Maccount->validateLogin($data);
		
		echo json_encode($res);
	}


	function logout() {
		// If the user signed in via Entra, federate the sign-out so they are
		// also signed out at Microsoft. Otherwise just clear the local session.
		$user = $this->session->userdata('user');
		$isAzure = is_array($user) && (($user['auth_source'] ?? null) === 'azure');

		if ($isAzure) {
			$this->load->library('azure_auth');
			$logoutUrl = $this->azure_auth->build_logout_url();
			$this->session->sess_destroy();
			redirect($logoutUrl);
			return;
		}

		$this->session->sess_destroy();
		redirect('');
	}


}
