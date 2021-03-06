<?php
/**
 * Abstract base class for all archive file inspectors.
 *
 * @author     Hecks
 * @copyright  (c) 2010-2013 Hecks
 * @license    Modified BSD
 * @version    2.1
 */
abstract class ArchiveReader
{
	// ------ Class variables and methods -----------------------------------------

	/**
	 * Unpacks data from a binary string.
	 *
	 * This method helps in particular to fix unpacking of unsigned longs on 32-bit
	 * systems due to PHP internal quirks.
	 *
	 * @param   string   $format    format codes for unpacking
	 * @param   string   $data      the packed string
	 * @param   boolean  $fixLongs  should unsigned longs be fixed?
	 * @return  array    the unpacked data
	 */
	public static function unpack($format, $data, $fixLongs=true)
	{
		$unpacked = unpack($format, $data);
		$longs = 'VNL';

		// Fix conversion of unsigned longs on 32-bit systems
		if ($fixLongs && PHP_INT_SIZE <= 4 && preg_match("/[{$longs}]++/", $format)) {
			$codes = explode('/', $format);
			foreach ($unpacked as $key => $value) {
				$code = array_shift($codes);
				if (strpos($longs, $code[0]) !== false && $value < 0) {
					$unpacked[$key] = $value + 0x100000000; // converts to float
				}
			}
		}

		return $unpacked;
	}

	/**
	 * Converts two longs to a float to represent a 64-bit integer on 32-bit
	 * systems, otherwise returns the integer.
	 *
	 * If more precision is needed, the bcmath functions should be used.
	 *
	 * @param   integer  $low   the low 32 bits
	 * @param   integer  $high  the high 32 bits
	 * @return  float/integer
	 */
	public static function int64($low, $high)
	{
		return ($low + ($high * 0x100000000));
	}

	/**
	 * Converts DOS standard timestamps to UNIX timestamps.
	 *
	 * @param   integer  $dostime  DOS timestamp
	 * @return  integer  UNIX timestamp
	 */
	public static function dos2unixtime($dostime)
	{
		$sec  = 2 * ($dostime & 0x1f);
		$min  = ($dostime >> 5) & 0x3f;
		$hrs  = ($dostime >> 11) & 0x1f;
		$day  = ($dostime >> 16) & 0x1f;
		$mon  = ($dostime >> 21) & 0x0f;
		$year = (($dostime >> 25) & 0x7f) + 1980;

		return mktime($hrs, $min, $sec, $mon, $day, $year);
	}

	/**
	 * Calculates the size of the given file.
	 *
	 * This is fiddly on 32-bit systems for sizes larger than 2GB due to internal
	 * limitations - filesize() returns a signed long - and so needs hackery.
	 *
	 * @param   string   $file  full path to the file
	 * @return  integer/float   the file size in bytes
	 */
	public static function getFileSize($file)
	{
		// 64-bit systems should be OK
		if (PHP_INT_SIZE > 4)
			return filesize($file);

		// Hack for Windows
		if (DIRECTORY_SEPARATOR === '\\') {
			$com = new COM('Scripting.FileSystemObject');
			$f = $com->GetFile($file);
			return abs($f->Size);
		}

		// Hack for *nix
		return abs(trim(shell_exec('stat -c %s '.escapeshellarg($file))));
	}

	/**
	 * Returns human-readable byte sizes as formatted strings.
	 *
	 * @param   integer  $bytes  the size to format
	 * @param   integer  $round  decimal places limit
	 * @return  string   human-readable size
	 */
	public static function formatSize($bytes, $round=1)
	{
		$suffix = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		for ($i = 0; $bytes > 1024 && isset($suffix[$i+1]); $i++) {$bytes /= 1024;}
		return round($bytes,$round).' '.$suffix[$i];
	}

	// ------ Instance variables and methods ---------------------------------------

	/**
	 * The last error message.
	 * @var string
	 */
	public $error = '';

	/**
	 * The number of files in the archive file/data.
	 * @var integer
	 */
	public $fileCount = 0;

	/**
	 * Default constructor for loading and analyzing archive files.
	 *
	 * @param   string   $file        path to the archive file
	 * @param   boolean  $isFragment  true if file is an archive fragment
	 * @param   array    $range       the start and end byte positions
	 * @return  void
	 */
	public function __construct($file=null, $isFragment=false, array $range=null)
	{
		if ($file) $this->open($file, $isFragment, $range);
	}

	/**
	 * Opens a handle to the archive file and analyzes the archive contents,
	 * optionally within a defined byte range only.
	 *
	 * @param   string   $file        path to the file
	 * @param   boolean  $isFragment  true if file is an archive fragment
	 * @param   array    $range       the start and end byte positions
	 * @return  boolean  false if archive analysis fails
	 */
	public function open($file, $isFragment=false, array $range=null)
	{
		$this->reset();
		$this->isFragment = $isFragment;
		if (!$this->setRange($range)) {return false;}

		if (!$file || !($archive = realpath($file)) || !is_file($archive)) {
			$this->error = "File does not exist ($file)";
			return false;
		}

		$this->file = $archive;
		$this->fileSize = self::getFileSize($archive);
		if (!$this->end) {$this->end = $this->fileSize - 1;}
		if (!$this->checkRange()) {return false;}

		// Open the file handle
		$this->handle = fopen($archive, 'rb');
		$this->rewind();

		return $this->analyze();
	}

	/**
	 * Loads data up to maxReadBytes and analyzes the archive contents, optionally
	 * within a defined byte range only.
	 *
	 * This method is recommended when dealing with file fragments.
	 *
	 * @param   string   $data        archive data to be analyzed
	 * @param   boolean  $isFragment  true if data is an archive fragment
	 * @param   array    $range       the start and end byte positions
	 * @return  boolean  false if archive analysis fails
	 */
	public function setData($data, $isFragment=false, array $range=null)
	{
		$this->reset();
		$this->isFragment = $isFragment;
		if (!$this->setRange($range)) {return false;}

		if (($dsize = strlen($data)) == 0) {
			$this->error = 'No data was passed, nothing to analyze';
			return false;
		}

		// Store the data locally up to max bytes
		$data = ($dsize > $this->maxReadBytes) ? substr($data, 0, $this->maxReadBytes) : $data;
		$this->dataSize = strlen($data);
		if (!$this->end) {$this->end = $this->dataSize - 1;}
		if (!$this->checkRange()) {return false;}
		$this->data = $data;

		$this->rewind();
		return $this->analyze();
	}

	/**
	 * Closes any open file handle and unsets any stored data.
	 *
	 * @return  void
	 */
	public function close()
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
			$this->handle = null;
		}
		$this->data = '';
	}

	/**
	 * Sets the maximum number of stored data bytes to analyze.
	 *
	 * @param   integer  $bytes  the max bytes to read
	 * @return  void
	 */
	public function setMaxReadBytes($bytes)
	{
		if (is_int($bytes) && $bytes > 0) {
			$this->maxReadBytes = $bytes;
		}
	}

	/**
	 * A full summary will be returned by default when converting the archive
	 * object to a string, such as when echoing it.
	 *
	 * @return  string  archive summary
	 */
	public function __toString()
	{
		return print_r($this->getSummary(true), true);
	}

	/**
	 * Magic method for accessing protected properties.
	 *
	 * @return  mixed
	 * @throws  LogicException
	 */
	public function __get($name)
	{
		// For backwards compatibility
		if ($name == 'file') {return $this->file;}

		throw new LogicException('Cannot access protected property '.get_class($this).'::$'.$name);
	}

	/**
	 * Convenience method that outputs a summary list of the archive information,
	 * useful for pretty-printing.
	 *
	 * @return  array  archive summary
	 */
	abstract public function getSummary();

	/**
	 * Parses the stored archive info and returns a list of records for each of the
	 * files in the archive.
	 *
	 * @return  mixed  an array of file records or false if none are available
	 */
	abstract public function getFileList();

	/**
	 * Path to the archive file (if any).
	 * @var string
	 */
	protected $file = '';

	/**
	 * File handle for the current archive.
	 * @var resource
	 */
	protected $handle;

	/**
	 * The maximum number of stored data bytes to analyze.
	 * @var integer
	 */
	protected $maxReadBytes = 1048576;

	/**
	 * The maximum length of filenames (for sanity checking).
	 * @var integer
	 */
	protected $maxFilenameLength = 256;

	/**
	 * Is this a file/data fragment?
	 * @var boolean
	 */
	protected $isFragment = false;

	/**
	 * The stored archive file data.
	 * @var string
	 */
	protected $data = '';

	/**
	 * The size in bytes of the currently stored data.
	 * @var integer
	 */
	protected $dataSize = 0;

	/**
	 * The size in bytes of the archive file.
	 * @var integer
	 */
	protected $fileSize = 0;

	/**
	 * The starting position for the analysis.
	 * @var integer
	 */
	protected $start = 0;

	/**
	 * The ending position for the analysis.
	 * @var integer
	 */
	protected $end = 0;

	/**
	 * The number of bytes to analyze from the $start position.
	 * @var integer
	 */
	protected $length = 0;

	/**
	 * The current position relative to the $start position.
	 * @var integer
	 */
	protected $offset = 0;

	/**
	 * Parses the archive data and stores the results locally.
	 *
	 * @return  boolean  false if parsing fails
	 */
	abstract protected function analyze();

	/**
	 * Sets the absolute start and end positions in the file/data to be analyzed
	 * (zero-indexed and inclusive of the end byte).
	 *
	 * @param   array    $range  the start and end byte positions
	 * @return  boolean  false if ranges are invalid
	 */
	protected function setRange(array $range=null)
	{
		$start = isset($range[0]) ? (int) $range[0] : 0;
		$end   = isset($range[1]) ? (int) $range[1] : 0;

		if ($start != $range[0] || $end != $range[1] || $start < 0 || $end < 0) {
			$this->error = "Start ($start) and end ($end) points must be positive integers";
			return false;
		}
		if ($end < $start) {
			$this->error = "End point ($end) must be higher than start point ($start)";
			return false;
		}
		$this->start = $start;
		$this->end = $end;

		return $this->checkRange();
	}

	/**
	 * Determines whether the currently set start and end ranges are within the
	 * bounds of the available data, and if not sets an error message.
	 *
	 * @return  boolean
	 */
	protected function checkRange()
	{
		$this->length = $this->end - $this->start + 1;
		$mlen = $this->file ? $this->fileSize : $this->dataSize;
		if ($mlen && ($this->end >= $mlen || $this->start >= $mlen || $this->length < 1)) {
			$this->error = "Byte range ({$this->start}-{$this->end}) is invalid";
			return false;
		}
		$this->error = '';

		return true;
	}

	/**
	 * Returns data within the given absolute byte range of the current file/data.
	 *
	 * @param   array  $range   the absolute start and end positions
	 * @return  string|boolean  the requested data or false on error
	 */
	protected function getRange(array $range)
	{
		// Check that the requested range is valid
		$original = array($this->start, $this->end, $this->length);
		if (!$this->setRange($range)) {
			list($this->start, $this->end, $this->length) = $original;
			return false;
		}

		// Get the data
		$this->seek(0);
		$data = $this->read($this->length);

		// Restore the original range
		list($this->start, $this->end, $this->length) = $original;

		return $data;
	}

	/**
	 * Saves data within the given absolute byte range of the current file/data to
	 * the destination file.
	 *
	 * @param   array   $range        the absolute start and end positions
	 * @param   string  $destination  full path of the file to create
	 * @return  integer/boolean  number of bytes written or false on error
	 */
	protected function saveRange(array $range, $destination)
	{

		// Check that the requested range is valid
		$original = array($this->start, $this->end, $this->length);
		if (!$this->setRange($range)) {
			list($this->start, $this->end, $this->length) = $original;
			return false;
		}

		// Write the buffered data to disk
		$this->seek(0);
		@$fh = fopen($destination, 'wb');
		$rlen = $this->length;
		$written = 0;
		while ($this->offset < $this->length) {
			$data = $this->read(min(1024, $rlen));
			$rlen -= strlen($data);
			@$written += fwrite($fh, $data);
		}
		@fclose($fh);

		// Restore the original range
		list($this->start, $this->end, $this->length) = $original;

		return $written;
	}

	/**
	 * Reads the given number of bytes from the archive file/data and moves the
	 * offset pointer forward.
	 *
	 * @param   integer  $num  number of bytes to read
	 * @return  string   the byte string
	 * @throws  InvalidArgumentException
	 * @throws  RangeException
	 */
	protected function read($num)
	{
		if ($num == 0) return '';

		// Check that enough data is available
		$newPos = $this->offset + $num;
		if ($num < 1 || $newPos > $this->length)
			throw new InvalidArgumentException("Could not read {$num} bytes from offset {$this->offset}");

		// Read the requested bytes
		if ($this->file && is_resource($this->handle)) {
			$read = fread($this->handle, $num);
		} elseif ($this->data) {
			$read = substr($this->data, $this->tell(), $num);
		}

		// Confirm the read length
		if (!isset($read) || (($rlen = strlen($read)) < $num)) {
			$rlen = isset($rlen) ? $rlen : 'none';
			$this->error = "Not enough data to read ({$num} bytes requested, {$rlen} available)";
			throw new RangeException($this->error);
		}

		// Move the data pointer
		$this->offset = $newPos;

		return $read;
	}

	/**
	 * Moves the current offset pointer to a position in the stored data or file
	 * relative to the start position.
	 *
	 * Note that seeking in files past the 2GB limit on 32-bit systems is either
	 * impossible or needs an incredibly slow hack due to the fseek() pointer not
	 * behaving after 2GB. The only real solution here is to use a 64-bit system.
	 *
	 * @param   integer  $pos  new pointer position
	 * @return  void
	 * @throws  RuntimeException
	 * @throws  InvalidArgumentException
	 */
	protected function seek($pos)
	{
		if ($pos > $this->length || $pos < 0)
			throw new InvalidArgumentException("Could not seek to {$pos} (max: {$this->length})");

		if ($this->file && is_resource($this->handle)) {
			$max = PHP_INT_MAX;
			$file_pos = $this->start + $pos;
			if ($file_pos >= $max) {
				$this->error = 'The file is too large for this PHP version (> '.self::formatSize($max).')';
				throw new RuntimeException($this->error);
			}
			fseek($this->handle, $file_pos, SEEK_SET);
		}

		$this->offset = $pos;
	}

	/**
	 * Provides the absolute position within the current file/data rather than
	 * the offset relative to the defined start position.
	 *
	 * @return  integer  the absolute file/data position
	 */
	protected function tell()
	{
		if ($this->file && is_resource($this->handle))
			return ftell($this->handle);

		return $this->start + $this->offset;
	}

	/**
	 * Sets the file/data offset pointer to the starting position.
	 *
	 * @return  void
	 */
	protected function rewind()
	{
		if ($this->file && is_resource($this->handle)) {
			rewind($this->handle);
		}
		$this->seek(0);
	}

	/**
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		$this->close();
		$this->file = '';
		$this->fileSize = 0;
		$this->dataSize = 0;
		$this->start = 0;
		$this->end = 0;
		$this->length = 0;
		$this->offset = 0;
		$this->error = '';
		$this->isFragment = false;
		$this->fileCount = 0;
	}

} // End ArchiveInfo class
