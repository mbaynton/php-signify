<?php

namespace DrupalAssociation\Signify;

class Verifier
{
    const COMMENTHDR = 'untrusted comment: ';
    const COMMENTHDRLEN = 19;
    const COMMENTMAXLEN = 1024;

    // Allowed checksum list verification algorithms and their base64-encoded lengths.
    protected $HASH_ALGO_BASE64_LENGTHS = array('SHA256' => 64, 'SHA512' => 128);

    /**
     * @var string
     */
    protected $publicKeyRaw;

    /**
     * @var VerifierB64Data
     */
    protected $publicKey;

    /**
     * Verifier constructor.
     *
     * @param string $public_key
     *   A public key generated by the BSD signify application.
     */
     function __construct($public_key_raw) {
         $this->publicKeyRaw = $public_key_raw;
     }

    /**
     * Get the raw public key in use.
     *
     * @return string
     *   The public key.
     */
     public function getPublicKeyRaw(){
         return $this->publicKeyRaw;
     }

    /**
     * @return \DrupalAssociation\Signify\VerifierB64Data
     */
     public function getPublicKey() {
         if (!$this->publicKey) {
             $this->publicKey = $this->parseB64String($this->publicKeyRaw, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
         }
         return $this->publicKey;
     }

    /**
     * Parse the contents of a base 64 encoded file.
     *
     * @param string $b64
     *   The file contents.
     * @param int $length
     *   The length of the data, either 32 or 64 bytes.
     *
     * @return \DrupalAssociation\Signify\VerifierB64Data
     */
     public function parseB64String($b64, $length) {
         $parts = explode("\n", $b64);
         if (count($parts) !== 3) {
             throw new VerifierException("Invalid format; must contain two newlines, one after comment and one after base64");
         }
         $comment = $parts[0];
         if (substr($comment, 0, self::COMMENTHDRLEN) !== self::COMMENTHDR) {
             throw new VerifierException(sprintf("Invalid format; comment must start with '%s'", self::COMMENTHDR));
         }
         if (strlen($comment) > self::COMMENTHDRLEN + self::COMMENTMAXLEN) {
             throw new VerifierException(sprintf("Invalid format; comment longer than %d bytes", self::COMMENTMAXLEN));
         }
         return new VerifierB64Data($parts[1], $length);
     }

    /**
     * Verify a string message.
     *
     * @param string $signed_message
     *   The string contents of the signify signature and message (e.g. the contents of a .sig file.)
     *
     * @return string
     *   The message if the verification passed.
     * @throws \SodiumException
     * @throws VerifierException
     *   Thrown when the message was not verified by the signature.
     */
    public function verifyMessage($signed_message) {
        $pubkey = $this->getPublicKey();

        // Simple split of signify signature and embedded message; input validation occurs in parseB64String().
        $embedded_message_index = 0;
        for($i = 1; $i <= 2 && $embedded_message_index !== false; $i++) {
            $embedded_message_index = strpos($signed_message, "\n", $embedded_message_index + 1);
        }
        $signature = substr($signed_message, 0, $embedded_message_index + 1);
        $message = substr($signed_message, $embedded_message_index + 1);
        if ($message === false) {
            $message = '';
        }

        $sig = $this->parseB64String($signature, SODIUM_CRYPTO_SIGN_BYTES);
        if ($pubkey->keyNum !== $sig->keyNum) {
            throw new VerifierException('verification failed: checked against wrong key');
        }
        $valid = sodium_crypto_sign_verify_detached($sig->data, $message, $pubkey->data);
        if (!$valid) {
            throw new VerifierException('Signature did not match');
        }
        return $message;
    }

    /**
     * Verify a signed checksum list, and then verify the checksum for each file in the list.
     *
     * @param string $signed_checksum_list
     *   Contents of a signify signature file whose message is a file checksum list.
     * @param string $working_directory
     *   A directory on the filesystem that the file checksum list is relative to.
     * @throws \SodiumException
     * @throws VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     * @return int
     *   The number of files verified.
     */
    public function verifyChecksumList($signed_checksum_list, $working_directory)
    {
        $checksum_list_raw = $this->verifyMessage($signed_checksum_list);
        $checksum_list = $this->parseChecksumList($checksum_list_raw, true);
        $verified_count = 0;

        /**
         * @var VerifierFileChecksum $file_checksum
         */
        foreach ($checksum_list as $file_checksum)
        {
            $actual_hash = @hash_file(strtolower($file_checksum->algorithm), $working_directory . DIRECTORY_SEPARATOR . $file_checksum->filename);
            if ($actual_hash === false) {
                throw new VerifierException("File \"$file_checksum->filename\" in the checksum list could not be read.");
            }
            if (empty($actual_hash) || strlen($actual_hash) < 64) {
                throw new VerifierException("Failure computing hash for file \"$file_checksum->filename\" in the checksum list.");
            }

            if (strcmp($actual_hash, $file_checksum->hex_hash) !== 0)
            {
                throw new VerifierException("File \"$file_checksum->filename\" does not pass checksum verification.");
            }

            $verified_count++;
        }

        return $verified_count;
    }

    /**
     * Verify the a signed checksum list file, and then verify the checksum for each file in the list.
     *
     * @param string $checksum_file
     *   The filename of a signed checksum list file.
     * @return int
     *   The number of files that were successfully verified.
     * @throws \SodiumException
     * @throws VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     */
    public function verifyChecksumFile($checksum_file) {
        $absolute_path = realpath($checksum_file);
        if (empty($absolute_path))
        {
            throw new VerifierException("The real path of checksum list file at \"$checksum_file\" could not be determined.");
        }
        $working_directory = dirname($absolute_path);
        $signed_checksum_list = file_get_contents($absolute_path);
        if (empty($signed_checksum_list))
        {
            throw new VerifierException("The checksum list file at \"$checksum_file\" could not be read.");
        }

        return $this->verifyChecksumList($signed_checksum_list, $working_directory);
    }

    protected function parseChecksumList($checksum_list_raw, $list_is_trusted)
    {
        $lines = explode("\n", $checksum_list_raw);
        $verified_checksums = array();
        foreach ($lines as $line) {
            if (trim($line) == '') {
                continue;
            }

            if (substr($line, 0, 1) === '\\') {
                throw new VerifierException('Filenames with problematic characters are not yet supported.');
            }

            $algo = substr($line, 0, strpos($line, ' '));
            if (empty($this->HASH_ALGO_BASE64_LENGTHS[$algo])) {
                throw new VerifierException("Algorithm \"$algo\" is unsupported for checksum verification.");
            }

            $filename_start = strpos($line, '(') + 1;
            $bytes_after_filename = $this->HASH_ALGO_BASE64_LENGTHS[$algo] + 4;
            $filename = substr($line, $filename_start, -$bytes_after_filename);

            $verified_checksum = new VerifierFileChecksum($filename, $algo, substr($line, -$this->HASH_ALGO_BASE64_LENGTHS[$algo]), $list_is_trusted);
            $verified_checksums[] = $verified_checksum;
        }

        return $verified_checksums;
    }

    /**
     * @param string $chained_signed_message
     *   The string contents of the root/intermediate chained signify signature and message (e.g. the contents of a .csig file.)
     * @param string $now
     *   The current date in ISO-8601 (YYYY-MM-DD) (optional.)
     *
     * @return string
     *   The message if the verification passed.
     * @throws \SodiumException
     * @throws VerifierException
     *   Thrown when the message was not verified.
     */
    public function verifyCsigMessage($chained_signed_message, $now = '')
    {
        $csig_lines = explode("\n", $chained_signed_message, 6);
        $root_signed_intermediate_key_and_validity = implode("\n", array_slice($csig_lines, 0, 5)) . "\n";
        $this->verifyMessage($root_signed_intermediate_key_and_validity);

        $valid_through_dt = \DateTimeImmutable::createFromFormat('Y-m-d', $csig_lines[2]);
        if (! $valid_through_dt instanceof \DateTimeImmutable)
        {
            throw new VerifierException('Unexpected valid-through date format.');
        }
        if (empty($now))
        {
            $now = date('Y-m-d');
        }
        $now_dt = \DateTimeImmutable::createFromFormat('Y-m-d', $now);
        if (! $now_dt instanceof \DateTimeImmutable)
        {
            throw new VerifierException('Unexpected date format of current date.');
        }

        $diff = $now_dt->diff($valid_through_dt);
        if ($diff->invert) {
            throw new VerifierException(sprintf('The intermediate key expired %d day(s) ago.', $diff->days));
        }

        $intermediate_pubkey = implode("\n", array_slice($csig_lines, 3, 2)) . "\n";
        $chained_verifier = new self($intermediate_pubkey);
        $signed_message = implode("\n", array_slice($csig_lines, 5));
        return $chained_verifier->verifyMessage($signed_message);
    }
}
