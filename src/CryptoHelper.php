<?php

namespace AS2;

use phpseclib\File\ASN1;

/**
 * TODO: Implement pure methods without "openssl_pkcs7"
 * openssl_pkcs7 doesn't work with binary data
 */
class CryptoHelper
{
    /**
     * Extract the message integrity check (MIC) from the digital signature
     *
     * @param MimePart|string $payload
     * @param string $algo Default is SHA256
     * @param bool $includeHeaders
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function calculateMIC($payload, $algo = 'sha256', $includeHeaders = true)
    {
        $digestAlgorithm = str_replace('-', '', strtolower($algo));
        if (! in_array($digestAlgorithm, hash_algos())) {
            throw new \InvalidArgumentException('Unknown hash algorithm');
        }
        if (! ($payload instanceof MimePart)) {
            $payload = MimePart::fromString($payload);
        }
        // $digest = base64_encode(openssl_digest($payload, $digestAlgorithm, true));
        $digest = base64_encode(hash(
            $digestAlgorithm,
            $includeHeaders ? $payload : $payload->getBody(),
            true
        ));
  
        return $digest . ', ' . $algo;
    }

    /**
     * Sign data which contains mime headers
     *
     * @param string|MimePart $data
     * @param string $cert
     * @param string $key
     * @param array $headers
     * @param array $micAlgo
     * @return MimePart
     * @throws \RuntimeException
     */
    public static function sign($data, $cert, $key = null, $headers = [], $micAlgo = null)
    {
        if ($data instanceof MimePart) {
            $data = self::getTempFilename($data->toString());
        }
        $temp = self::getTempFilename();
        if (! openssl_pkcs7_sign($data, $temp, $cert, $key, $headers, PKCS7_DETACHED)) {
            throw new \RuntimeException(
                sprintf('Failed to sign S/Mime message. Error: "%s".', openssl_error_string())
            );
        }
        $payload = MimePart::fromString(file_get_contents($temp));

        // TODO: refactory
        // Some servers don't support "x-pkcs7"
        $contentType = $payload->getHeaderLine('content-type');
        $contentType = str_replace('x-pkcs7', 'pkcs7', $contentType);
        if ($micAlgo) {
            $contentType = preg_replace('/micalg=(.+);/i', 'micalg="' . $micAlgo . '";', $contentType);
        }
        /** @var MimePart $payload */
        $payload = $payload->withHeader('Content-Type', $contentType);
        foreach ($payload->getParts() as $key => $part) {
            if ($part->isPkc7Signature()) {
                $payload->removePart($key);
                $payload->addPart(
                    $part->withHeader('Content-Type',
                        'application/pkcs7-signature; name=smime.p7s; smime-type=signed-data')
                );
            }
        }

        return $payload;
    }

    /**
     * Create a temporary file into temporary directory
     *
     * @param string $content
     * @return string The temporary file generated
     */
    public static function getTempFilename($content = null)
    {
        $dir = sys_get_temp_dir();
        $filename = tempnam($dir, 'phpas2_');
        if ($content) {
            file_put_contents($filename, $content);
        }

        return $filename;
    }

    /**
     * @param string|MimePart $data
     * @param array $caInfo Information about the trusted CA certificates to use in the verification process
     * @return bool
     */
    public static function verify($data, $caInfo = [])
    {
        if ($data instanceof MimePart) {
            $data = self::getTempFilename((string) $data);
        }
        // TODO: implement
        // return openssl_pkcs7_verify($data, PKCS7_BINARY | PKCS7_NOSIGS | PKCS7_NOVERIFY, null, $caInfo);
        return openssl_pkcs7_verify($data, PKCS7_BINARY | PKCS7_NOSIGS | PKCS7_NOVERIFY);
    }

    /**
     * @param string|MimePart $data
     * @param string|array $cert
     * @param int $cipher
     * @return MimePart
     * @throws \RuntimeException
     */
    public static function encrypt($data, $cert, $cipher = OPENSSL_CIPHER_3DES)
    {
        if ($data instanceof MimePart) {
            $data = self::getTempFilename((string) $data);
        }
        if (is_string($cipher) && defined('OPENSSL_CIPHER_' . strtoupper($cipher))) {
          $cipher = constant('OPENSSL_CIPHER_' . strtoupper($cipher));
        }
        $temp = self::getTempFilename();
        if (! openssl_pkcs7_encrypt($data, $temp, (array) $cert, [], PKCS7_BINARY, $cipher)) {
            throw new \RuntimeException(
                sprintf('Failed to encrypt S/Mime message. Error: "%s".', openssl_error_string())
            );
        }

        return MimePart::fromString(file_get_contents($temp));
    }

    /**
     * @param string|MimePart $data
     * @param string $cert
     * @param string|null $key
     * @param string|null $keyPassphrase
     * @return MimePart
     * @throws \RuntimeException
     */
    public static function decrypt($data, $cert, $key = null, $keyPassphrase = null)
    {
        if ($data instanceof MimePart) {
            $data = self::getTempFilename((string) $data);
        }
        $temp = self::getTempFilename();
        //if there is a passphrase for the private key, it must be appended to the key. 
        $keyArray = (empty($keyPassphrase) ? $key : [$key, $keyPassphrase]);
        
        if (! openssl_pkcs7_decrypt($data, $temp, $cert, $keyArray)) {
            throw new \RuntimeException(
                sprintf('Failed to decrypt S/Mime message. Error: "%s".', openssl_error_string())
            );
        }

        return MimePart::fromString(file_get_contents($temp));
    }

    /**
     * Compress data
     *
     * @param string|MimePart $data
     * @param string $encoding
     * @return MimePart
     */
    public static function compress($data, $encoding = MimePart::ENCODING_BASE64)
    {
        if ($data instanceof MimePart) {
            $content = $data->toString();
        } else {
            $content = is_file($data) ? file_get_contents($data) : $data;
        }
        $headers = [
            'Content-Type' => MimePart::TYPE_PKCS7_MIME . '; name="smime.p7z"; smime-type=' . MimePart::SMIME_TYPE_COMPRESSED,
            'Content-Description' => 'S/MIME Compressed Message',
            'Content-Disposition' => 'attachment; filename="smime.p7z"',
//            'Content-Encoding' => $encoding,
            'Content-Transfer-Encoding' => $encoding,
        ];

        /*$content = ASN1Helper::encodeDER(gzcompress($content), [
          'type' => ASN1::TYPE_GENERAL_STRING //OCTET_STRING
        ]);*/
        $contentAsn1Compressed =
      ASN1Helper::encodeDER([
                'version' => 0,
                'compression' => [
                    'algorithm' => ASN1Helper::ALG_ZLIB_OID,
                ],
//                'encapContentInfo' => gzcompress($content)
                'payload' => [
                    'contentType' => ASN1Helper::ENVELOPED_DATA_OID,
                    'content' => gzcompress($content),
                ],
            ], ASN1Helper::COMPRESSED_DATA_MAP);
        
        $content = ASN1Helper::encodeDER([
            'contentType' => ASN1Helper::COMPRESSED_DATA_OID,
            'content' => $contentAsn1Compressed
      ,
        ], ASN1Helper::CONTENT_INFO_MAP_WITH_FILTER);
        
        if ($encoding == MimePart::ENCODING_BASE64) {
            $content = Utils::encodeBase64($content);
        }

        return new MimePart($headers, $content);
    }

    /**
     * Decompress data
     *
     * @param string|MimePart $data
     * @param string $encoding
     * @return string
     */
    public static function decompress($data, $encoding = MimePart::ENCODING_BASE64)
    {
        if ($data instanceof MimePart) {
            $encoding = $data->getHeaderLine('Content-Encoding');
            if (empty($encoding)) {
              $encoding = $data->getHeaderLine('Content-Transfer-Encoding');
            }
            $data = $data->getBody();
        }

        if ($encoding == MimePart::ENCODING_BASE64) {
            $data = base64_decode($data);
        }

        $payload = null;
        try {
          $payload = ASN1Helper::decodeDER($data, ASN1Helper::CONTENT_INFO_MAP_WITH_FILTER);
        } catch (\Exception $err) {
          $payload = ASN1Helper::decodeDER($data, ASN1Helper::CONTENT_INFO_MAP);
        }

        if ($payload['contentType'] == ASN1Helper::COMPRESSED_DATA_OID) {
            $compressed = ASN1Helper::decodeDER($payload['content'], ASN1Helper::COMPRESSED_DATA_MAP);
            if (empty($compressed['payload'])) {
                throw new \RuntimeException('Invalid compressed data.');
            }
            $data = gzuncompress($compressed['payload']['content']);
        }

        return MimePart::fromString($data);
    }

}
