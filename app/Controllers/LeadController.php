<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Models\Lead;
use App\Services\LeadScorer;
use App\Services\OpenAIClient;
use App\Core\DB;
use App\Helpers;

class LeadController
{
    public function __construct(private array $env) {}

    public function view(): void
    {
        Auth::requireLogin();
        $leadId = (int)($_GET['id'] ?? 0);
        $lead = Lead::findWithEmail(Auth::user()['id'], $leadId);
        if (!$lead) { http_response_code(404); echo 'Lead not found'; return; }
        $checks = Lead::checks($leadId);
        $isPartial = isset($_GET['partial'])
            || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isPartial) {
            View::partial('lead/view', ['lead'=>$lead, 'checks'=>$checks]);
        } else {
            View::render('lead/view', ['lead'=>$lead, 'checks'=>$checks]);
        }
    }

    public function reprocess(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $leadId = (int)($_POST['id'] ?? 0);
        $lead = Lead::findWithEmail(Auth::user()['id'], $leadId);
        if (!$lead) { Helpers::redirect('/leads'); }
        $settings = \App\Models\Setting::getByUser(Auth::user()['id']);
        $mode = $settings['filter_mode'] ?? 'algorithmic';
        $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
        if ($mode === 'gpt' && $openaiKey) {
            $client = new OpenAIClient($openaiKey);
            $res = $client->classify($lead);
        } else {
            $res = LeadScorer::compute($lead);
        }
        $id = Lead::upsertFromEmail($lead, $res);
        Lead::addCheck($id, Auth::user()['id'], $res['mode'], (int)$res['score'], (string)$res['reason']);
        Helpers::redirect('/lead/view?id=' . $leadId);
    }

    public function mark(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $leadId = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'unknown';
        if (in_array($status, ['genuine','spam'])) {
            Lead::manualMark($leadId, Auth::user()['id'], $status);
        }
        Helpers::redirect('/lead/view?id=' . $leadId);
    }
}

