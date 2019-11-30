<?php

namespace Drupal\Signify\Tests;

use Drupal\Signify\ChecksumList;
use Drupal\Signify\FailedCheckumFilter;
use Drupal\Signify\VerifierException;
use PHPUnit\Framework\TestCase;
use Drupal\Signify\Verifier;

/**
 *  Tests for the \Drupal\Signify\Verifier class.
 *
 * @author David Strauss
 * @author Mike Baynton
 * @author Peter Wolanin
 */
class SignifyTest extends TestCase
{

    /**
     * Check for valid syntax.
     */
    public function testIsThereAnySyntaxError()
    {
        $var = new Verifier('drupal');
        $this->assertTrue(is_object($var));
    }

    /**
     * Tests a successful message verification.
     */
    public function testPositiveVerification()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/test1-php-signify.pub');
        $var = new Verifier($public_key);
        $this->assertSame($public_key, $var->getPublicKeyRaw());
        $signature = file_get_contents(__DIR__ . '/fixtures/artifact1.php.sig');
        $message = file_get_contents(__DIR__ . '/fixtures/artifact1.php');
        $this->assertEquals($message, $var->verifyMessage($signature . $message));
    }

    /**
     * Tests a successful embedded signature and message verification.
     */
    public function testPositiveVerificationEmbeddedMessage()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/embed.pub');
        $var = new Verifier($public_key);
        $this->assertSame($public_key, $var->getPublicKeyRaw());
        $signature_and_message = file_get_contents(__DIR__ . '/fixtures/embed.sig');
        $message = file_get_contents(__DIR__ . '/fixtures/embed.txt');
        $this->assertEquals($message, $var->verifyMessage($signature_and_message));
    }

    /**
     * Test using the wrong public key.
     *
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage checked against wrong key
     */
    public function testIncorrectPubkey()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/test2-php-signify.pub');
        $var = new Verifier($public_key);
        $signature = file_get_contents(__DIR__ . '/fixtures/artifact1.php.sig');
        $message = file_get_contents(__DIR__ . '/fixtures/artifact1.php');
        $var->verifyMessage($signature . $message);
    }

    /**
     * Test with a modified message string compared to what was signed.
     *
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage Signature did not match
     */
    public function testIncorrectMessage()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/test1-php-signify.pub');
        $var = new Verifier($public_key);
        $signature = file_get_contents(__DIR__ . '/fixtures/artifact1.php.sig');
        $message = file_get_contents(__DIR__ . '/fixtures/artifact1.php') . 'bad message';
        $var->verifyMessage($signature . $message);
    }

    /**
     * @dataProvider invalidPublicKeys
     */
    public function testInvalidPublicKey($public_key, $exception_message)
    {
        try {
            $verifier = new Verifier($public_key);
            $verifier->getPublicKey();
        } catch (VerifierException $e) {
            if (strpos($e->getMessage(), $exception_message) === false) {
                $this->fail(sprintf('testInvalidPublicKey exception message did not contain expected "%s"', $exception_message));
            }

            return;
        }

        $this->fail('testInvalidPublicKey: Expected VerifierException not thrown.');
    }

    /**
     * Data provider for testInvalidPublicKey()
     */
    public function invalidPublicKeys()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/test1-php-signify.pub');

        $missing_comment = implode("\n", array_slice(explode("\n", $public_key), 1));
        $truncated_key = substr($public_key, 0, -3) . "\n";
        $wrong_comment = str_replace('untrusted', 'super trustworthy', $public_key);

        $pk_parts = explode("\n", $public_key);
        $epic_comment = str_pad($pk_parts[0], Verifier::COMMENTHDRLEN + Verifier::COMMENTMAXLEN + 1, 'x') . "\n" . implode("\n", array_slice($pk_parts, 1));

        return array(
            array($missing_comment, 'must contain two newlines'),
            array($truncated_key, 'Data does not match expected length'),
            array($wrong_comment, 'comment must start with'),
            array($epic_comment, sprintf('comment longer than %d bytes', Verifier::COMMENTMAXLEN))
        );
    }

    public function testVerifyChecksumList()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $signed_checksumlist = file_get_contents(__DIR__ . '/fixtures/checksumlist.sig');
        $this->assertEquals(1, $var->verifyChecksumList($signed_checksumlist, __DIR__ . '/fixtures'));
    }

    public function testVerifyChecksumFile()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $this->assertEquals(1, $var->verifyChecksumFile(__DIR__ . '/fixtures/checksumlist.sig'));
    }

    /**
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage File "payload-compromised.zip" does not pass checksum verification.
     */
    public function testChecksumFileCompromisedArchive()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $var->verifyChecksumFile(__DIR__ . '/fixtures/checksumlist-compromised.sig');
    }

    /**
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage The real path of checksum list file at
     */
    public function testChecksumFileMissing()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $var->verifyChecksumFile(__DIR__ . '/fixtures/not_a_file');
    }

    /**
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage could not be read.
     */
    public function testChecksumFileItselfUnreadable()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $var->verifyChecksumFile(__DIR__ . '/fixtures');
    }

    /**
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage File "payload.zip" in the checksum list could not be read.
     */
    public function testChecksumListUnreadableFile()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $signed_checksumlist = file_get_contents(__DIR__ . '/fixtures/checksumlist.sig');
        $var->verifyChecksumList($signed_checksumlist, __DIR__ . '/intentionally wrong path');
    }

    public function testDefaultGetNow()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/checksumlist.pub');
        $var = new Verifier($public_key);
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->assertLessThan(5, $now->diff($var->getNow())->s);
    }

    /**
     * @dataProvider positiveCsigVerificationProvider
     */
    public function testPositiveCsigVerification($now)
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/intermediate/root.pub');
        $var = new Verifier($public_key);
        $chained_signed_message = file_get_contents(__DIR__ . '/fixtures/intermediate/checksumlist.csig');
        $message = $var->verifyCsigMessage($chained_signed_message, $now);

        $this->assertEquals(
            "SHA512 (payload.zip) = c3d7e5cd9b117c602e6a3063a9c6f28171a65678fbc0789c1517eecd02f4542267f2db0a59e32a35763abcf0f7601df2b7e2d792c1fa2b9f18bfafa61c121380\n",
            $message
        );
    }

    public function positiveCsigVerificationProvider()
    {
        return array(
            array(new \DateTime('2000-01-01', new \DateTimeZone('UTC'))),
            array(new \DateTime('2019-09-09', new \DateTimeZone('UTC'))),
            array(new \DateTime('2019-09-10', new \DateTimeZone('UTC'))),
        );
    }

    /**
     * @expectedException \Drupal\Signify\VerifierException
     * @expectedExceptionMessage The intermediate key expired 1 day(s) ago.
     */
    public function testExpiredCsigVerification()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/intermediate/root.pub');
        $var = new Verifier($public_key);
        $chained_signed_message = file_get_contents(__DIR__ . '/fixtures/intermediate/checksumlist.csig');
        $var->verifyCsigMessage($chained_signed_message, new \DateTime('2019-09-11', new \DateTimeZone('UTC')));
    }

    public function testVerifyCsigChecksumList()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/intermediate/root.pub');
        $var = new Verifier($public_key);
        $signed_checksumlist = file_get_contents(__DIR__ . '/fixtures/intermediate/checksumlist.csig');
        $this->assertEquals(1, $var->verifyCsigChecksumList($signed_checksumlist, __DIR__ . '/fixtures/intermediate', new \DateTime('2019-09-01', new \DateTimeZone('UTC'))));
    }

    public function testVerifyCsigChecksumFile()
    {
        $public_key = file_get_contents(__DIR__ . '/fixtures/intermediate/root.pub');
        $var = new Verifier($public_key);
        $this->assertEquals(1, $var->verifyCsigChecksumFile(__DIR__ . '/fixtures/intermediate/checksumlist.csig', new \DateTime('2019-09-01', new \DateTimeZone('UTC'))));
    }

    public function testMultipleFilesCsig() {
        $public_key = file_get_contents(__DIR__ . '/fixtures/multiple-files/root.pub');
        $var = new Verifier($public_key);
        $contents = file_get_contents(__DIR__ . '/fixtures/multiple-files/module.csig');
        $files = $var->verifyCsigMessage($contents, new \DateTime('2019-09-20', new \DateTimeZone('UTC')));
        $checksums = new ChecksumList($files, TRUE);

        // Validate expected checksums exist.
        $checksums->rewind();
        $a = $checksums->current();
        $this->assertEquals('a.txt', $a->filename);
        $this->assertCount(4, $checksums);

        // Validate failed checkusms.
        $failed_checksums = new FailedCheckumFilter($checksums, __DIR__ . '/fixtures/multiple-files');
        $failed_checksums->rewind();
        $b = $failed_checksums->current();
        $this->assertEquals('b.txt', $b->filename);
        $failed_checksums->next();
        $d = $failed_checksums->current();
        $this->assertEquals('d.txt', $d->filename);
        $this->assertCount(2, $failed_checksums);
    }
}
