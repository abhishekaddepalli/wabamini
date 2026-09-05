<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PlatformSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        // We handle JSON encoding/decoding manually in accessors to perform encryption
    ];

    /**
     * List of sensitive keys that should be encrypted in payment_gateways_config.
     */
    protected static array $sensitivePaymentKeys = [
        'secret_key',
        'webhook_secret',
        'key_secret',
        'client_secret',
        'api_key',
        'access_token',
    ];

    public function getValueAttribute($value)
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if ($this->key === 'mailer' && is_array($decoded)) {
            // Decrypt password
            if (isset($decoded['smtp']['password']) && !empty($decoded['smtp']['password'])) {
                try {
                    $decoded['smtp']['password'] = Crypt::decryptString($decoded['smtp']['password']);
                } catch (\Exception $e) {
                    // Fall back if not encrypted
                }
            }
            // Decrypt API key
            if (isset($decoded['resend']['api_key']) && !empty($decoded['resend']['api_key'])) {
                try {
                    $decoded['resend']['api_key'] = Crypt::decryptString($decoded['resend']['api_key']);
                } catch (\Exception $e) {
                    // Fall back if not encrypted
                }
            }
        }

        if ($this->key === 'payment_gateways_config' && is_array($decoded)) {
            if (isset($decoded['gateways']) && is_array($decoded['gateways'])) {
                foreach ($decoded['gateways'] as $gateway => &$config) {
                    if (is_array($config)) {
                        foreach (self::$sensitivePaymentKeys as $secretKey) {
                            if (isset($config[$secretKey]) && !empty($config[$secretKey])) {
                                try {
                                    $config[$secretKey] = Crypt::decryptString($config[$secretKey]);
                                } catch (\Exception $e) {
                                    // Fall back if already plaintext or unencrypted
                                }
                            }
                        }
                    }
                }
            }
        }

        return $decoded;
    }

    public function setValueAttribute($value)
    {
        if ($this->key === 'mailer' && is_array($value)) {
            // Encrypt password
            if (isset($value['smtp']['password']) && !empty($value['smtp']['password'])) {
                try {
                    $value['smtp']['password'] = Crypt::encryptString($value['smtp']['password']);
                } catch (\Exception $e) {
                    // Fall back if encryption fails
                }
            }
            // Encrypt API key
            if (isset($value['resend']['api_key']) && !empty($value['resend']['api_key'])) {
                try {
                    $value['resend']['api_key'] = Crypt::encryptString($value['resend']['api_key']);
                } catch (\Exception $e) {
                    // Fall back if encryption fails
                }
            }
        }

        if ($this->key === 'payment_gateways_config' && is_array($value)) {
            if (isset($value['gateways']) && is_array($value['gateways'])) {
                foreach ($value['gateways'] as $gateway => &$config) {
                    if (is_array($config)) {
                        foreach (self::$sensitivePaymentKeys as $secretKey) {
                            if (isset($config[$secretKey]) && !empty($config[$secretKey])) {
                                try {
                                    $config[$secretKey] = Crypt::encryptString($config[$secretKey]);
                                } catch (\Exception $e) {
                                    // Fall back if encryption fails
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->attributes['value'] = json_encode($value);
    }
}
