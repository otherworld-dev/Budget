<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db\Trait;

use OCA\Budget\Attribute\Encrypted;
use OCA\Budget\Db\Trait\EncryptedFieldsTrait;
use OCA\Budget\Service\EncryptionService;
use OCP\AppFramework\Db\Entity;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EncryptedFieldsTrait is what keeps account numbers, IBANs and bank tokens
 * encrypted at rest. These tests drive it through a real EncryptionService
 * backed by a reversible fake cipher, so a "round trip" here is the same
 * prefix handling the mappers get in production.
 */
class EncryptedFieldsTraitTest extends TestCase {
	private ICrypto $crypto;
	private LoggerInterface $logger;
	private EncryptedFieldsTraitTestMapper $mapper;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		// Reversible stand-in for AES: easy to recognise, impossible to confuse
		// with the plaintext, and it fails loudly on anything it didn't produce.
		$this->crypto->method('encrypt')
			->willReturnCallback(fn(string $plain) => 'CIPHER[' . base64_encode(strrev($plain)) . ']');
		$this->crypto->method('decrypt')
			->willReturnCallback(function (string $cipher) {
				if (!preg_match('/^CIPHER\[(.*)\]$/', $cipher, $m)) {
					throw new \Exception('HMAC does not match');
				}
				return strrev(base64_decode($m[1]));
			});
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->mapper = new EncryptedFieldsTraitTestMapper();
		$this->mapper->init(new EncryptionService($this->crypto, $this->logger), EncryptedFieldsTraitTestEntity::class);
	}

	private function makeEntity(?string $accountNumber = '12345678', ?string $iban = 'GB29NWBK60161331926819', ?string $name = 'Main'): EncryptedFieldsTraitTestEntity {
		$entity = new EncryptedFieldsTraitTestEntity();
		$entity->setAccountNumber($accountNumber);
		$entity->setIban($iban);
		$entity->setName($name);
		return $entity;
	}

	// ── discovery ───────────────────────────────────────────────────

	public function testDiscoversOnlyPropertiesMarkedEncrypted(): void {
		$names = $this->mapper->callGetEncryptedPropertyNames();

		sort($names);
		$this->assertSame(['accountNumber', 'iban'], $names);
	}

	public function testIsEncryptedPropertyDistinguishesMarkedFromPlainFields(): void {
		$this->assertTrue($this->mapper->callIsEncryptedProperty('accountNumber'));
		$this->assertTrue($this->mapper->callIsEncryptedProperty('iban'));
		$this->assertFalse($this->mapper->callIsEncryptedProperty('name'));
		$this->assertFalse($this->mapper->callIsEncryptedProperty('doesNotExist'));
	}

	public function testDiscoversEncryptedPropertiesInheritedFromAParentEntity(): void {
		$mapper = new EncryptedFieldsTraitTestMapper();
		$mapper->init(new EncryptionService($this->crypto, $this->logger), EncryptedFieldsTraitTestChildEntity::class);

		$names = $mapper->callGetEncryptedPropertyNames();

		sort($names);
		$this->assertSame(['accountNumber', 'iban', 'token'], $names);
	}

	// ── encryptEntity ───────────────────────────────────────────────

	public function testEncryptEntityEncryptsMarkedFieldsAndLeavesOthersAlone(): void {
		$entity = $this->makeEntity();

		$this->mapper->callEncryptEntity($entity);

		$this->assertStringStartsWith('enc:', $entity->getAccountNumber());
		$this->assertStringStartsWith('enc:', $entity->getIban());
		$this->assertStringNotContainsString('12345678', $entity->getAccountNumber());
		$this->assertSame('Main', $entity->getName());
	}

	public function testEncryptEntityReturnsTheSameInstance(): void {
		$entity = $this->makeEntity();

		$this->assertSame($entity, $this->mapper->callEncryptEntity($entity));
	}

	public function testEncryptEntityMarksEncryptedFieldsAsUpdatedSoTheyArePersisted(): void {
		$entity = $this->makeEntity();
		$entity->resetUpdatedFields();

		$this->mapper->callEncryptEntity($entity);

		$updated = $entity->getUpdatedFields();
		$this->assertArrayHasKey('accountNumber', $updated);
		$this->assertArrayHasKey('iban', $updated);
		$this->assertArrayNotHasKey('name', $updated);
	}

	public function testEncryptEntityLeavesNullFieldsNull(): void {
		$entity = $this->makeEntity(null, null);
		$this->crypto->expects($this->never())->method('encrypt');

		$this->mapper->callEncryptEntity($entity);

		$this->assertNull($entity->getAccountNumber());
		$this->assertNull($entity->getIban());
	}

	public function testEncryptEntityLeavesEmptyStringsEmpty(): void {
		$entity = $this->makeEntity('', '');
		$this->crypto->expects($this->never())->method('encrypt');

		$this->mapper->callEncryptEntity($entity);

		$this->assertSame('', $entity->getAccountNumber());
		$this->assertSame('', $entity->getIban());
	}

	public function testEncryptEntityTwiceDoesNotDoubleEncrypt(): void {
		$entity = $this->makeEntity();
		$this->mapper->callEncryptEntity($entity);
		$onceEncrypted = $entity->getAccountNumber();

		$this->mapper->callEncryptEntity($entity);

		$this->assertSame($onceEncrypted, $entity->getAccountNumber());
		$this->mapper->callDecryptEntity($entity);
		$this->assertSame('12345678', $entity->getAccountNumber());
	}

	public function testEncryptEntityIsANoOpBeforeInitialisation(): void {
		$mapper = new EncryptedFieldsTraitTestMapper();
		$entity = $this->makeEntity();

		$mapper->callEncryptEntity($entity);

		$this->assertSame('12345678', $entity->getAccountNumber());
	}

	public function testEncryptEntityIsANoOpForAnEntityWithNoEncryptedFields(): void {
		$mapper = new EncryptedFieldsTraitTestMapper();
		$mapper->init(new EncryptionService($this->crypto, $this->logger), EncryptedFieldsTraitTestPlainEntity::class);
		$entity = new EncryptedFieldsTraitTestPlainEntity();
		$entity->setLabel('visible');
		$this->crypto->expects($this->never())->method('encrypt');

		$mapper->callEncryptEntity($entity);

		$this->assertSame('visible', $entity->getLabel());
	}

	// ── decryptEntity ───────────────────────────────────────────────

	public function testEncryptThenDecryptRoundTripsEveryField(): void {
		$entity = $this->makeEntity('12345678', 'GB29NWBK60161331926819', 'Main');

		$this->mapper->callEncryptEntity($entity);
		$this->mapper->callDecryptEntity($entity);

		$this->assertSame('12345678', $entity->getAccountNumber());
		$this->assertSame('GB29NWBK60161331926819', $entity->getIban());
		$this->assertSame('Main', $entity->getName());
	}

	public function testRoundTripPreservesNonAsciiAndWhitespace(): void {
		$value = "  Zürich IBAN ✓ 01\n";
		$entity = $this->makeEntity($value);

		$this->mapper->callEncryptEntity($entity);
		$this->mapper->callDecryptEntity($entity);

		$this->assertSame($value, $entity->getAccountNumber());
	}

	/**
	 * Rows written before encryption was introduced hold plaintext. Reading
	 * them must hand back the value untouched, never try to decrypt it and
	 * never blank it out.
	 */
	public function testDecryptEntityReturnsLegacyPlaintextUnchanged(): void {
		$entity = $this->makeEntity('12345678', 'GB29NWBK60161331926819');
		$this->crypto->expects($this->never())->method('decrypt');

		$this->mapper->callDecryptEntity($entity);

		$this->assertSame('12345678', $entity->getAccountNumber());
		$this->assertSame('GB29NWBK60161331926819', $entity->getIban());
	}

	public function testDecryptEntityHandlesAMixOfLegacyAndEncryptedFields(): void {
		$entity = $this->makeEntity('12345678', 'GB29NWBK60161331926819');
		// Only the IBAN was written after encryption existed.
		$entity->setIban('enc:CIPHER[' . base64_encode(strrev('GB29NWBK60161331926819')) . ']');

		$this->mapper->callDecryptEntity($entity);

		$this->assertSame('12345678', $entity->getAccountNumber());
		$this->assertSame('GB29NWBK60161331926819', $entity->getIban());
	}

	public function testDecryptEntityLeavesNullAndEmptyFieldsAlone(): void {
		$entity = $this->makeEntity(null, '');
		$this->crypto->expects($this->never())->method('decrypt');

		$this->mapper->callDecryptEntity($entity);

		$this->assertNull($entity->getAccountNumber());
		$this->assertSame('', $entity->getIban());
	}

	/**
	 * A changed instance secret or a corrupted row cannot be decrypted. The
	 * field comes back null (so the ciphertext is never shown to the user as
	 * if it were their account number) and the failure is logged.
	 */
	public function testDecryptEntityNullsAFieldThatCannotBeDecrypted(): void {
		$entity = $this->makeEntity('enc:garbage-from-another-key', 'GB29NWBK60161331926819');
		$this->logger->expects($this->once())->method('error');

		$this->mapper->callDecryptEntity($entity);

		$this->assertNull($entity->getAccountNumber());
		$this->assertSame('GB29NWBK60161331926819', $entity->getIban());
	}

	public function testDecryptEntityIsANoOpBeforeInitialisation(): void {
		$mapper = new EncryptedFieldsTraitTestMapper();
		$entity = $this->makeEntity('enc:CIPHER[abc]');

		$mapper->callDecryptEntity($entity);

		$this->assertSame('enc:CIPHER[abc]', $entity->getAccountNumber());
	}

	public function testDecryptEntitiesDecryptsEveryEntityAndKeepsOrder(): void {
		$first = $this->makeEntity('111');
		$second = $this->makeEntity('222');
		$this->mapper->callEncryptEntity($first);
		$this->mapper->callEncryptEntity($second);

		$result = $this->mapper->callDecryptEntities([$first, $second]);

		$this->assertCount(2, $result);
		$this->assertSame($first, $result[0]);
		$this->assertSame($second, $result[1]);
		$this->assertSame('111', $result[0]->getAccountNumber());
		$this->assertSame('222', $result[1]->getAccountNumber());
	}

	public function testDecryptEntitiesOfAnEmptyListIsEmpty(): void {
		$this->assertSame([], $this->mapper->callDecryptEntities([]));
	}

	// ── getEncryptedValue (manual UPDATE queries) ───────────────────

	public function testGetEncryptedValueEncryptsAPlaintextField(): void {
		$entity = $this->makeEntity('12345678');

		$value = $this->mapper->callGetEncryptedValue($entity, 'accountNumber');

		$this->assertStringStartsWith('enc:', $value);
		// The entity itself stays decrypted for the caller.
		$this->assertSame('12345678', $entity->getAccountNumber());
		$this->assertSame('12345678', (new EncryptionService($this->crypto, $this->logger))->decrypt($value));
	}

	public function testGetEncryptedValuePassesNullAndEmptyThrough(): void {
		$this->assertNull($this->mapper->callGetEncryptedValue($this->makeEntity(null), 'accountNumber'));
		$this->assertSame('', $this->mapper->callGetEncryptedValue($this->makeEntity(''), 'accountNumber'));
	}

	/**
	 * A value still carrying the "enc:" prefix after a read means decryption
	 * failed. Writing it back would persist ciphertext nobody can read, so the
	 * trait returns null instead.
	 */
	public function testGetEncryptedValueRefusesToPersistAValueThatIsStillEncrypted(): void {
		$entity = $this->makeEntity('enc:undecryptable');

		$this->assertNull($this->mapper->callGetEncryptedValue($entity, 'accountNumber'));
	}

	public function testGetEncryptedValueReturnsNullForAnUnknownProperty(): void {
		$this->assertNull($this->mapper->callGetEncryptedValue($this->makeEntity(), 'noSuchField'));
	}

	/**
	 * The docblock promises the raw value for a property that isn't encrypted.
	 * Entity getters are magic (__call), so the fallback must look for the
	 * backing field rather than a getter method.
	 */
	public function testGetEncryptedValueReturnsTheRawValueForAPlainProperty(): void {
		$this->assertSame('Main', $this->mapper->callGetEncryptedValue($this->makeEntity(), 'name'));
		$this->assertNull($this->mapper->callGetEncryptedValue($this->makeEntity(name: null), 'name'));
	}

	public function testGetEncryptedValueReturnsANumericPlainPropertyAsAString(): void {
		$entity = $this->makeEntity();
		$entity->setId(42);

		$this->assertSame('42', $this->mapper->callGetEncryptedValue($entity, 'id'));
	}
}

/**
 * Stand-in mapper: the trait only needs a host class, and this one exposes
 * the protected API.
 */
class EncryptedFieldsTraitTestMapper {
	use EncryptedFieldsTrait;

	public function init(EncryptionService $service, string $entityClass): void {
		$this->initializeEncryption($service, $entityClass);
	}

	public function callGetEncryptedPropertyNames(): array {
		return $this->getEncryptedPropertyNames();
	}

	public function callIsEncryptedProperty(string $name): bool {
		return $this->isEncryptedProperty($name);
	}

	public function callEncryptEntity(Entity $entity): Entity {
		return $this->encryptEntity($entity);
	}

	public function callDecryptEntity(Entity $entity): Entity {
		return $this->decryptEntity($entity);
	}

	public function callDecryptEntities(array $entities): array {
		return $this->decryptEntities($entities);
	}

	public function callGetEncryptedValue(Entity $entity, string $propertyName): ?string {
		return $this->getEncryptedValue($entity, $propertyName);
	}
}

/**
 * @method string|null getAccountNumber()
 * @method void setAccountNumber(?string $value)
 * @method string|null getIban()
 * @method void setIban(?string $value)
 * @method string|null getName()
 * @method void setName(?string $value)
 */
class EncryptedFieldsTraitTestEntity extends Entity {
	#[Encrypted]
	protected $accountNumber;

	#[Encrypted]
	protected $iban;

	protected $name;
}

/**
 * @method string|null getToken()
 * @method void setToken(?string $value)
 */
class EncryptedFieldsTraitTestChildEntity extends EncryptedFieldsTraitTestEntity {
	#[Encrypted]
	protected $token;
}

/**
 * @method string|null getLabel()
 * @method void setLabel(?string $value)
 */
class EncryptedFieldsTraitTestPlainEntity extends Entity {
	protected $label;
}
