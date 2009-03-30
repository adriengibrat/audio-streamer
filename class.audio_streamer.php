<?php
/* By Adrien Gibrat, adrien.gibrat@gmail.com, 31/05/07
 * 
 * Audio Streamer allow to convert virtually any audio file format to a mp3 stream or file.
 * 
 * Published under the LGPL licence.
 * 
 * In order to work, this class needs external decoding/encoding tool.
 * You can find the list of usefull decoders and encoders below,
 * you can comment or decomment decoder/encoder line to match with your actual 
 * server configuration.
 * 
 * By exemple, if your lame version has been compiled with ogg support,
 * you can use lame with --ogginput, so decomment the third lines of decoders and encoders and
 * comment the fourth line of decoder.
 * 
 * If you don't have ffmpeg available, you can comment all ffmpeg decoder lines.
 * And if ffmpeg is installed, check what formats are supported 
 * (i.e witch libraries are on your system) with the command line `ffmpeg -formats`.
 * You must comment all unsupported formats, and/or add lines for the supported ones.
 * 
 * You can even use a general option for all unspecified formats by uncommenting the * line,
 * if theire is no specific decoder, the class will try to decode the audio file 
 * with the * decoder (ffmpeg by default)
 * 
 * This class should works on windows and *nix systems, for windows system you'll need to install
 * the tee utilitie (tee.com) to use the save_stream() method (or you'll need to rewrite it).
 * 
 * All listed decoders should be available for both systems.
 * I join the lame encoder for window and debian, this should be usefull for most people.
 * 
 * This class is not complete and not documented, sorry, i want to add an internal cache sytem, 
 * but i know that you can use any personnal caching system for a custom solution 
 * by using save_stream() method.
 */

class audio_streamer
{
	public 
	$source,
	$name,
	$target,
	$bitrate, 
	$stereo,
	$decoder,
	$encoder,
	$command;
	private
	$type,
	$bitrates = array('16','32','48','64','96','112','128'), 
	$stereos = array('j','s','m','f'),
	$encoders = array(
		'wav'=>array('lame','%1$s --silent --nores -h -m %3$s -b %4$d %2$s -'),
		'mp3'=>array('lame','%1$s --silent --nores -h -m %3$s -b %4$d --mp3input %2$s -'),
		//'ogg'=>array('lame','%1$s --silent --nores -h -m %3$s -b %4$d --ogginput %2$s -'),
		'*'=>array('lame','%1$s --silent --nores -h -m %3$s -b %4$d - -'),
	),
	$decoders = array(
		'wav'=>array('lame',''),
		'mp3'=>array('lame',''),
		//'ogg'=>array('lame',''),
		'ogg'=>array('oggdec','%1$s -Q -o - %2$s | '),
		'flac'=>array('flac','%1$s -dcs %2$s | '),
		'mpc'=>array('mppdec','%1$s --silent --wav %2$s - | '),
		'ape'=>array('mac','%1$s %2$s - -d | '),
		'wv'=>array('wvunpack','%1$s -q %2$s - | '),
		'ofr'=>array('ofr','%1$s --decode --silent %2$s --output - | '),
		'la'=>array('la','%1$s -cout %2$s | '),
		'pac'=>array('lpac','%1$s -x %2$s - | '),
		'shn'=>array('shorten','%1$s -x %2$s - | '),
		'aac'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'ac3'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'aif'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'3gp'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'mov'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'raw'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		'wma'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
		//'*'=>array('ffmpeg','%1$s -v 0 -i %2$s -f wav - | '),
	);

	public function __construct($source, $path = '/usr/bin/')
	{
		$this->type = strtolower(pathinfo($source, PATHINFO_EXTENSION));
		$this->name = basename($source, '.' . $this->type);
		$this->target = dirname($source);

		if(!is_file($source))
			throw new Exception($source . ' source not found');
		$this->source = $this->secure_path($source);

		if(array_key_exists($this->type, $this->decoders))
			$this->decoder = $this->decoders[$this->type];
		elseif(array_key_exists('*', $this->decoders))
			$this->decoder = $this->decoders['*'];
		else
			throw new Exception('No available decoder for ' . $this->type);

		if(array_key_exists($this->type, $this->encoders))
			$this->encoder = $this->encoders[$this->type];
		elseif(array_key_exists('*', $this->encoders))
			$this->encoder = $this->encoders['*'];
		else
			throw new Exception('No available encoder for ' . $this->type);

		if(is_string($path))
		{
			$exe = preg_match('/^WIN/',PHP_OS) ? '.exe' : '';
			$decoder = $path . DIRECTORY_SEPARATOR . $this->decoder[0] . $exe;
			$encoder = $path . DIRECTORY_SEPARATOR . $this->encoder[0] . $exe;
		}
		elseif(is_array($path))
		{
			if(array_key_exists($this->decoder[0], $path))
				$decoder = $path[$this->decoder[0]];
			if(array_key_exists($this->encoder[0], $path))
				$encoder = $path[$this->encoder[0]];
		}

		if(!file_exists($decoder))
			throw new Exception($decoder . ' decoder not found');
		else
			$this->decoder[0] = $this->secure_path($decoder);

		if(!file_exists($encoder))
			throw new Exception($encoder . ' encoder not found');
		else
			$this->encoder[0] = $this->secure_path($encoder);
	}

	public function stream($bitrate = 64, $stereo = 'j')
	{
		$this->convert($bitrate, $stereo);
		$this->headers();
		passthru($this->command);
	}

	public function save($target = null, $bitrate = 64, $stereo = 'j')
	{
		$this->convert($bitrate, $stereo);
		$this->set_target($target);
		exec($this->command . ' > ' . $this->secure_path($this->target));
		return $this->target;
	}

	public function save_stream($target = null, $bitrate = 64, $stereo = 'j')
	{
		$this->convert($bitrate, $stereo);
		$this->set_target($target);
		$this->headers();
		passthru($this->command . ' | tee ' . $this->secure_path($this->target));
		return $this->target;
	}

	private function set_target($target)
	{
		if($target == 'tmp')
			$this->target = $_ENV['TMP'];
		elseif($target)
			$this->target = $target;

		if(is_dir($this->target))
			$this->target .= DIRECTORY_SEPARATOR . 
			$this->name . '-' . $this->stereo . '-' . $this->bitrate . 'kbps.mp3';

		if(!is_writable(dirname($this->target)))
			throw new Exception($this->target . ' target not writable');
	}

	private function convert($bitrate, $stereo)
	{
		if(!in_array($bitrate, $this->bitrates))
			throw new Exception($bitrate . 'is an invalid bitrate');
		else
			$this->bitrate = intval($bitrate);

		if(!in_array($stereo, $this->stereos))
			throw new Exception($stereo . 'is an invalid stereo mode');
		else
			$this->stereo = strtolower($stereo);

		$this->command = sprintf($this->decoder[1], $this->decoder[0], $this->source) .
		sprintf($this->encoder[1], $this->encoder[0], $this->source, $this->stereo, $this->bitrate);
	}

	private function headers()
	{
		header('Content-type: audio/mpeg-3');
		header('Content-Disposition: filename=' . $this->name . '.mp3');
		header('Content-Transfer-Encoding: binary');
	}

	private function secure_path($path)
	{
		if(file_exists($path))
			$path = realpath($path);
		if(strpos($path, ' '))
			$path = '"' . $path . '"';
		return $path;
	}
}
?>