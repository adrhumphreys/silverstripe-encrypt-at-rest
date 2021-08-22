<?php

namespace Madmatt\EncryptAtRest;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\File;
use Defuse\Crypto\Key;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

class AtRestCryptoService
{

    /**
     * @param string   $raw
     * @param null|Key $key
     *
     * @return string
     * @throws EnvironmentIsBrokenException
     * @throws BadFormatException
     */
    public function encrypt($raw, $key = null)
    {
        $key = $this->getKey($key);
        return Crypto::Encrypt((string)$raw, $key);
    }

    /**
     * @param string   $ciphertext
     * @param null|Key $key
     *
     * @return string
     * @throws BadFormatException
     * @throws EnvironmentIsBrokenException
     * @throws WrongKeyOrModifiedCiphertextException
     */
    public function decrypt($ciphertext, $key = null)
    {
        $key = $this->getKey($key);
        return Crypto::Decrypt($ciphertext, $key);
    }

    /**
     * @param \SilverStripe\Assets\File $file
     * @param null|Key                  $key
     *
     * @return false|\SilverStripe\Assets\File
     * @throws BadFormatException
     * @throws EnvironmentIsBrokenException
     */
    public function encryptFile($file, $key = null)
    {
        $key = $this->getKey($key);
        try {
            $currentPath = $this->getFullPath($file);
            $encryptedFilename = $currentPath . '.enc';
            File::encryptFile($currentPath, $encryptedFilename, $key);
            $filename = $file->getFilename() . '.enc';
            $file->deleteFile();
            $file->File->setField('Filename', $filename);
            $file->write();
            $file->protectFile();

            return $file;
        } catch (Exception $e) {
            Injector::inst()->get(LoggerInterface::class)
                ->error(sprintf('Encryption exception while parsing "%s": %s', $file->Name, $e->getMessage()));
            return false;
        }

    }

    /**
     * @param \SilverStripe\Assets\File    $file
     * @param null|Key $key
     *
     * @return false|\SilverStripe\Assets\File
     * @throws BadFormatException
     * @throws EnvironmentIsBrokenException
     */
    public function decryptFile($file, $key = null)
    {
        $key = $this->getKey($key);
        try {
            $currentPath = $this->getFullPath($file);
            $decryptedFilename = str_replace('.enc', '', $currentPath);
            File::decryptFile($currentPath, $decryptedFilename, $key);
            $filename = str_replace('.enc', '', $file->getFilename());
            $file->deleteFile();
            $file->File->setField('Filename', $filename);
            $file->write();
            $file->protectFile();

            return $file;
        } catch (Exception $e) {
            Injector::inst()->get(LoggerInterface::class)
                ->error(sprintf('Decryption exception while parsing "%s": %s', $file->Name, $e->getMessage()));
            return false;
        }
    }

    /**
     * @param $rawKey
     *
     * @return Key
     * @throws BadFormatException
     * @throws EnvironmentIsBrokenException
     */
    public function getKey($rawKey)
    {
        // If this is already a \Defuse\Crypto\Key object, just return it
        if ($rawKey instanceof Key) {
            return $rawKey;
        }

        $envKey = Environment::getEnv('ENCRYPT_AT_REST_KEY');
        if ($rawKey === null && $envKey) {
            // Retrieve key from _ss_env, if set
            $rawKey = $envKey;
        }

        if ($rawKey === null) {
            throw new InvalidArgumentException('Can\'t encrypt without a key. Define ENCRYPT_AT_REST_KEY, or pass the $key argument.');
        }

        return Key::LoadFromAsciiSafeString($rawKey);
    }


    /**
     * @param \SilverStripe\Assets\File $file
     * @param string $visibility
     * @return mixed
     * @throws ReflectionException
     */
    protected function getFullPath($file, $visibility = AssetStore::VISIBILITY_PROTECTED)
    {
        $assetStore = Injector::inst()->get(AssetStore::class);

        $filesystem = $visibility === AssetStore::VISIBILITY_PROTECTED
            ? $assetStore->getProtectedFilesystem()
            : $assetStore->getPublicFilesystem();
        $adapter = $filesystem->getAdapter();

        $reflection = new ReflectionClass(get_class($assetStore));
        $method = $reflection->getMethod('getFileID');
        $method->setAccessible(true);
        $fileID = $method->invokeArgs($assetStore, [$file->Filename, $file->Hash, $file->Variant]);

        return $adapter->applyPathPrefix($fileID);
    }

}
