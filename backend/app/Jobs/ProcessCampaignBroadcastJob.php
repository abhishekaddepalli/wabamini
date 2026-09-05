<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\AiAgent;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Services\Flow\FlowRunner;
use App\Services\Channels\ChannelManager;
use App\Services\AIProviderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessCampaignBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $campaignId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channelManager, AIProviderService $aiService): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (!$campaign) {
            Log::error("ProcessCampaignBroadcastJob: Campaign ID {$this->campaignId} not found.");
            return;
        }

        if ($campaign->status === 'completed' || $campaign->status === 'failed') {
            return;
        }

        $campaign->update(['status' => 'sending']);

        try {
            $channelConnection = ChannelConnection::where('tenant_id', $campaign->tenant_id)
                ->findOrFail($campaign->channel_connection_id);
            $driver = $channelManager->driver($channelConnection->channel_type);
            $credentials = $channelConnection->decrypted_credentials;

            // 1. Resolve targeted contacts list
            $query = Contact::where('tenant_id', $campaign->tenant_id);
            $filter = $campaign->audience_filter;

            if (isset($filter['type'])) {
                if ($filter['type'] === 'lifecycle_stage' && !empty($filter['value'])) {
                    $query->where('lifecycle_stage', $filter['value']);
                } elseif ($filter['type'] === 'tags' && !empty($filter['value'])) {
                    $query->whereJsonContains('tags', $filter['value']);
                } elseif ($filter['type'] === 'contacts' && !empty($filter['value'])) {
                    $ids = is_array($filter['value']) ? $filter['value'] : explode(',', $filter['value']);
                    $query->whereIn('id', array_map('intval', $ids));
                }
            }

            $contacts = $query->get();

            if ($contacts->isEmpty()) {
                $campaign->update([
                    'status' => 'completed',
                    'error_log' => 'No contacts matched the audience filter segment.',
                ]);
                return;
            }

            $campaign->update(['total_contacts' => $contacts->count()]);

            // 2. Loop & Dispatch
            foreach ($contacts as $contact) {
                // A. Check opt-out enforcement
                $optOuts = $contact->opted_out_channels ?: [];
                if (in_array($channelConnection->channel_type, $optOuts)) {
                    CampaignDispatch::create([
                        'campaign_id' => $campaign->id,
                        'contact_id' => $contact->id,
                        'status' => 'failed',
                        'error_message' => 'Contact has opted out of this channel.',
                    ]);
                    continue;
                }

                // B. Check address suitability
                $isEmail = $channelConnection->channel_type === 'email';
                $destination = $isEmail ? $contact->email : $contact->phone;

                if (empty($destination)) {
                    CampaignDispatch::create([
                        'campaign_id' => $campaign->id,
                        'contact_id' => $contact->id,
                        'status' => 'failed',
                        'error_message' => $isEmail ? 'No email address configured.' : 'No phone number configured.',
                    ]);
                    continue;
                }

                // C. Resolve or create Conversation
                $externalChatId = $isEmail ? $contact->email : preg_replace('/[^0-9]/', '', $contact->phone);
                $conversation = Conversation::firstOrCreate([
                    'tenant_id' => $campaign->tenant_id,
                    'channel_connection_id' => $campaign->channel_connection_id,
                    'contact_id' => $contact->id,
                ], [
                    'external_chat_id' => $externalChatId,
                    'last_message_at' => now(),
                ]);

                // Telegram validation: Telegram Bot API requires a numeric chat_id
                if ($channelConnection->channel_type === 'telegram' && !preg_match('/^[0-9]+$/', (string)$conversation->external_chat_id)) {
                    CampaignDispatch::create([
                        'campaign_id' => $campaign->id,
                        'contact_id' => $contact->id,
                        'status' => 'failed',
                        'error_message' => 'Telegram Bot API requires the contact to initiate a conversation with the Telegram bot first to obtain a valid Telegram Chat ID.',
                    ]);
                    continue;
                }

                // D. Compile body text based on Campaign source type
                $bodyContent = '';
                $sendParams = [];

                if ($campaign->source_type === 'template') {
                    $template = MessageTemplate::find($campaign->message_template_id);
                    if (!$template) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => 'Bound message template not found.',
                        ]);
                        continue;
                    }

                    if ($template->type === 'email') {
                        // Compile visual email blocks
                        $html = '';
                        $blocks = $template->content['blocks'] ?? [];
                        foreach ($blocks as $block) {
                            if ($block['type'] === 'header') {
                                $html .= '<h2 style="font-size: 22px; font-weight: 800; font-family: sans-serif; color: #0A0A0A; margin: 10px 0 16px;">' . e($block['content']) . '</h2>';
                            } elseif ($block['type'] === 'paragraph') {
                                $html .= '<p style="font-size: 14px; line-height: 1.6; font-family: sans-serif; color: #6B6B6B; margin-bottom: 16px;">' . nl2br(e($block['content'])) . '</p>';
                            } elseif ($block['type'] === 'button') {
                                $html .= '<div style="margin: 20px 0;"><a href="' . e($block['url'] ?? '#') . '" style="background-color: #0A0A0A; color: #FFFFFF; font-family: sans-serif; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 12px; display: inline-block;">' . e($block['content']) . '</a></div>';
                            } elseif ($block['type'] === 'divider') {
                                $html .= '<hr style="border: 0; border-top: 1px solid #E8E8E6; margin: 24px 0;" />';
                            } elseif ($block['type'] === 'image') {
                                $html .= '<div style="margin-bottom: 16px;"><img src="' . e($block['src'] ?? '') . '" alt="" style="max-width: 100%; height: auto; border-radius: 6px;" /></div>';
                            }
                        }

                        // Substitute tags
                        $bodyContent = str_replace(
                            ['{{ contact.first_name }}', '{{ contact.last_name }}', '{{ contact.email }}'],
                            [$contact->first_name ?: 'there', $contact->last_name ?: '', $contact->email],
                            $html
                        );
                        $sendParams = [
                            'external_chat_id' => $contact->email,
                            'body' => "Subject: {$campaign->name}\n\n" . $bodyContent,
                        ];

                    } else {
                        // WhatsApp templates
                        $bodyText = $template->content['body']['text'] ?? '';
                        $bodyContent = str_replace(
                            ['{{1}}', '{{ contact.first_name }}'],
                            [$contact->first_name ?: 'there', $contact->first_name ?: 'there'],
                            $bodyText
                        );

                        if ($channelConnection->channel_type === 'whatsapp') {
                            // Meta Cloud API template structures
                            $sendParams = [
                                'external_chat_id' => $contact->phone,
                                'type' => 'template',
                                'template' => [
                                    'name' => $template->name,
                                    'language' => [
                                        'code' => $template->language ?: 'en_US',
                                    ],
                                    'components' => [
                                        [
                                            'type' => 'body',
                                            'parameters' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => $contact->first_name ?: 'there',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ];
                        } else {
                            // Plain text bypass for Baileys/SMS/Mock
                            $sendParams = [
                                'external_chat_id' => $contact->phone,
                                'body' => $bodyContent,
                            ];
                        }
                    }

                } elseif ($campaign->source_type === 'compose' || $campaign->source_type === 'custom') {
                    $rawText = $campaign->custom_message ?: '';
                    $bodyContent = $this->interpolateContactVariables($rawText, $contact);

                    if ($isEmail) {
                        $htmlBody = '';
                        if (!empty($campaign->media_url)) {
                            $htmlBody .= '<div style="margin-bottom: 20px; text-align: center;"><img src="' . e($campaign->media_url) . '" alt="Attachment" style="max-width: 100%; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);" /></div>';
                        }
                        $htmlBody .= '<p style="font-size: 14px; line-height: 1.6; font-family: sans-serif; color: #333333; margin-bottom: 20px;">' . nl2br(e($bodyContent)) . '</p>';
                        if (!empty($campaign->cta_button_text) && !empty($campaign->cta_button_url)) {
                            $btnUrl = $this->interpolateContactVariables($campaign->cta_button_url, $contact);
                            $htmlBody .= '<div style="margin: 24px 0; text-align: center;"><a href="' . e($btnUrl) . '" style="background-color: #0A0A0A; color: #FFFFFF; font-family: sans-serif; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 13px; display: inline-block;">' . e($campaign->cta_button_text) . '</a></div>';
                        }
                        $subject = $this->interpolateContactVariables($campaign->custom_subject ?: $campaign->name, $contact);
                        $sendParams = [
                            'external_chat_id' => $contact->email,
                            'subject' => $subject,
                            'body' => $htmlBody,
                        ];
                    } else {
                        $textWithButton = $bodyContent;
                        if (!empty($campaign->cta_button_text) && !empty($campaign->cta_button_url)) {
                            $textWithButton .= "\n\n👉 " . $campaign->cta_button_text . ": " . $campaign->cta_button_url;
                        }
                        if (!empty($campaign->media_url)) {
                            $sendParams = [
                                'external_chat_id' => $destination,
                                'type' => $campaign->media_type ?: 'image',
                                'media_url' => $campaign->media_url,
                                'body' => $textWithButton,
                                'caption' => $textWithButton,
                            ];
                        } else {
                            $sendParams = [
                                'external_chat_id' => $destination,
                                'body' => $textWithButton,
                            ];
                        }
                    }

                } elseif ($campaign->source_type === 'flow' || $campaign->source_type === 'agent') {
                    $agent = $campaign->ai_agent_id ? AiAgent::find($campaign->ai_agent_id) : null;
                    if ($campaign->source_type === 'agent' && !$agent) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => 'Bound outbound AI Agent not found.',
                        ]);
                        continue;
                    }

                    $flowId = $agent ? $agent->flow_id : $campaign->flow_id;

                    if (!$flowId) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => 'Selected AI Agent has no Automation Flow attached. Please attach a Flow to this AI Agent in AI Agent Manager before launching outbound campaigns.',
                        ]);
                        continue;
                    }

                    $flow = Flow::find($flowId);
                    if (!$flow) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => 'Automation flow attached to the AI Agent was not found.',
                        ]);
                        continue;
                    }

                    $flowVersion = $flow->publishedVersion ?? $flow->versions()->latest('version_number')->first();
                    if (!$flowVersion || empty($flowVersion->definition['nodes'])) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => 'Automation flow has no published or valid node definitions.',
                        ]);
                        continue;
                    }

                    $startNodeId = null;
                    foreach ($flowVersion->definition['nodes'] as $n) {
                        if (in_array($n['type'] ?? '', ['inbound_message', 'webhook_trigger', 'outbound_campaign'])) {
                            $startNodeId = $n['id'];
                            break;
                        }
                    }
                    if (!$startNodeId) {
                        $startNodeId = $flowVersion->definition['nodes'][0]['id'];
                    }

                    $execution = FlowExecution::create([
                        'tenant_id' => $campaign->tenant_id,
                        'flow_version_id' => $flowVersion->id,
                        'contact_id' => $contact->id,
                        'conversation_id' => $conversation->id,
                        'current_node_id' => $startNodeId,
                        'status' => 'running',
                        'context' => [
                            'variables' => [
                                'campaign.id' => $campaign->id,
                                'campaign.name' => $campaign->name,
                            ]
                        ]
                    ]);

                    /** @var FlowRunner $flowRunner */
                    $flowRunner = app(FlowRunner::class);
                    $flowRunner->execute($execution);

                    $execution->refresh();
                    if ($execution->status === 'failed' || !empty($execution->last_error)) {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => $execution->last_error ?: 'Flow execution failed to deliver outbound message.',
                        ]);
                    } else {
                        CampaignDispatch::create([
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'sent',
                            'error_message' => null,
                        ]);
                        $campaign->increment('sent_count');
                    }
                    continue;

                }

                // E.g., dispatch
                $res = $driver->sendMessage($credentials, $sendParams);

                // F. Save Message record
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'body' => $bodyContent,
                    'external_message_id' => $res['external_message_id'] ?? null,
                    'delivery_status' => $res['delivery_status'] ?? 'sent',
                    'error_message' => $res['error_message'] ?? null,
                ]);

                // G. Save Dispatch log
                CampaignDispatch::create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'message_id' => $message->id,
                    'status' => $message->delivery_status,
                    'error_message' => $message->error_message,
                ]);
            }

            $campaign->update(['status' => 'completed']);
            $campaign->recalculateStats();

            // Broadcast campaign completion
            \App\Services\NotificationService::createAndBroadcast(
                $campaign->tenant_id,
                null,
                'Campaign Completed',
                "The campaign broadcast '{$campaign->name}' has completed successfully.",
                'campaign_complete',
                ['campaign_id' => $campaign->id]
            );

        } catch (Exception $e) {
            Log::error("ProcessCampaignBroadcastJob failed: " . $e->getMessage());
            $campaign->update([
                'status' => 'failed',
                'error_log' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Interpolate contact fields into message content.
     */
    protected function interpolateContactVariables(?string $text, Contact $contact): string
    {
        if (empty($text)) {
            return '';
        }

        $fullName = trim(($contact->first_name ?: '') . ' ' . ($contact->last_name ?: ''));
        if (empty($fullName)) {
            $fullName = $contact->first_name ?: 'Valued Customer';
        }

        $tagsStr = is_array($contact->tags) ? implode(', ', $contact->tags) : ($contact->tags ?: '');

        $replacements = [
            '{{ contact.first_name }}' => $contact->first_name ?: 'there',
            '{{ contact.last_name }}' => $contact->last_name ?: '',
            '{{ contact.name }}' => $fullName,
            '{{ contact.email }}' => $contact->email ?: '',
            '{{ contact.phone }}' => $contact->phone ?: '',
            '{{ contact.lifecycle_stage }}' => $contact->lifecycle_stage ?: 'Lead',
            '{{ contact.tags }}' => $tagsStr,
            '{{ contact.id }}' => (string)$contact->id,
            '{{ contact.created_at }}' => $contact->created_at ? $contact->created_at->format('Y-m-d') : '',
            '{{1}}' => $contact->first_name ?: 'there',
        ];

        $interpolated = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Also interpolate dynamic custom_fields like {{ contact.custom_fields.company }}
        $interpolated = preg_replace_callback('/\{\{\s*contact\.custom_fields\.([a-zA-Z0-9_\-]+)\s*\}\}/', function ($matches) use ($contact) {
            $key = $matches[1];
            if (is_array($contact->custom_fields) && isset($contact->custom_fields[$key])) {
                return (string)$contact->custom_fields[$key];
            }
            return '';
        }, $interpolated);

        return $interpolated;
    }
}
