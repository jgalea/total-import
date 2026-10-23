<?php

namespace Importright\Tests\Unit;

use Importright\Reader\JsonReader;
use Importright\Reader\ReaderException;

final class JsonReaderTest extends FileCase {

	public function test_root_array_of_objects(): void {
		$reader  = new JsonReader( $this->file( '[{"id":1,"title":"A","active":true,"none":null,"price":9.5},{"id":2,"title":"B","active":false}]' ) );
		$records = $this->read_all( $reader );
		$this->assertSame( 2, $reader->total() );
		$this->assertSame(
			array(
				'id'     => '1',
				'title'  => 'A',
				'active' => '1',
				'none'   => '',
				'price'  => '9.5',
			),
			$records[0]
		);
		$this->assertSame( '0', $records[1]['active'] );
	}

	public function test_nested_objects_and_lists_flatten(): void {
		$records = $this->read_all( new JsonReader( $this->file( '[{"dims":{"w":1,"h":{"v":2}},"tags":["a","b"],"images":[{"src":"x.jpg"}],"empty":[]}]' ) ) );
		$record  = $records[0];
		$this->assertSame( '1', $record['dims.w'] );
		$this->assertSame( '2', $record['dims.h.v'] );
		$this->assertSame( 'a', $record['tags'] );
		$this->assertSame( 'a', $record['tags[1]'] );
		$this->assertSame( 'b', $record['tags[2]'] );
		$this->assertSame( 'x.jpg', $record['images.src'] );
		$this->assertSame( 'x.jpg', $record['images[1].src'] );
		$this->assertSame( '', $record['empty'] );
	}

	public function test_explicit_and_detected_paths(): void {
		$json = '{"meta":{"count":2},"data":{"items":[{"id":1},{"id":2}]}}';
		$path = $this->file( $json );
		foreach ( array( 'data.items', '$.data.items', '/data/items' ) as $json_path ) {
			$this->assertCount( 2, $this->read_all( new JsonReader( $path, array( 'json_path' => $json_path ) ) ), $json_path );
		}
		$detected = new JsonReader( $path );
		$this->assertSame( 'data.items', $detected->records_path() );
		$this->assertCount( 2, $this->read_all( $detected ) );
	}

	public function test_single_object_is_one_record(): void {
		$records = $this->read_all( new JsonReader( $this->file( '{"id":7,"name":"Only"}' ) ) );
		$this->assertSame( 'Only', $records[0]['name'] );
	}

	public function test_scalar_items_get_a_value_key(): void {
		$this->assertSame( array( array( 'value' => 'a' ), array( 'value' => '2' ) ), $this->read_all( new JsonReader( $this->file( '["a",2]' ) ) ) );
	}

	public function test_missing_path_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new JsonReader( $this->file( '{"a":[]}' ), array( 'json_path' => 'b.c' ) );
	}

	public function test_malformed_json_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new JsonReader( $this->file( '[{"a":1},' ) );
	}

	public function test_scalar_root_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new JsonReader( $this->file( '"text"' ) );
	}

	public function test_size_limit(): void {
		$this->expectException( ReaderException::class );
		new JsonReader( $this->file( '[{"a":"' . str_repeat( 'x', 2000 ) . '"}]' ), array( 'max_bytes' => 1024 ) );
	}

	public function test_big_integers_stay_exact(): void {
		$records = $this->read_all( new JsonReader( $this->file( '[{"id":12345678901234567890123}]' ) ) );
		$this->assertSame( '12345678901234567890123', $records[0]['id'] );
	}

	public function test_resumes_from_cursor(): void {
		$path                   = $this->file( '[{"i":0},{"i":1},{"i":2}]' );
		list( $first, $cursor ) = $this->read_some( new JsonReader( $path ), 1 );
		$this->assertSame( array( 1, 2 ), array_keys( $this->read_all( new JsonReader( $path ), $cursor ) ) );
		$this->assertCount( 1, $first );
	}
}
