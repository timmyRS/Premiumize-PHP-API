<?php
/**
 * Premiumize.class.php
 *
 * @link https://www.premiumize.me/api
 * @version 1.0
 * @author Tim "timmyRS" Speckhals
 * @copyright Copyright (c) 2017, timmyRS
 */

define("PREMIUMIZE_CUSTOMER_ID", "XXXXXXXXX");
define("PREMIUMIZE_PIN", "xxxxxxxxxxxxxxxx");
define("PREMIUMIZE_USE_HTTPS", true);

abstract class Premiumize
{
	/**
	 * Sends a request to the Premiumize API.
	 * Usually you don't need to use this function.
	 *
	 * @param string API Endpoint
	 * @param array Arguments
	 * @return array JSON
	 */
	public static function request($endpoint, $rawArgs = [])
	{
		$args = [];
		foreach($rawArgs as $key => $value)
		{
			if(gettype($value) == "array")
			{
				foreach($value as $val)
				{
					array_push($args, $key."[]=".rawurlencode($val));
				}
			}
			else
			{
				array_push($args, $key."=".rawurlencode($value));
			}
		}
		array_push($args, "customer_id=".PREMIUMIZE_CUSTOMER_ID);
		array_push($args, "pin=".PREMIUMIZE_PIN);
		$json = json_decode(file_get_contents((PREMIUMIZE_USE_HTTPS?"https://www":"http://http").".premiumize.me/api".$endpoint."?".join("&",$args)), true);
		if($json["status"] != "success")
		{
			throw new Exception("Request was unsuccessful: ".json_encode($json, JSON_PRETTY_PRINT));
		}
		return $json;
	}

	/**
	 * Returns an array of account informations with the following keys:
	 * "premium_until" The timestamp of when your premium will end.
	 * "available_points" How many Fair Use Points you have remaining.
	 * "points_used" Percentage of how much of your fair use points you have used.
	 *
	 * @return array<string> Account Information Array
	 */
	public static function accountInfo()
	{
		$json = Premiumize::request("/account/info");
		return ["premium_until" => $json["premium_until"], "points_used" => $json["limit_used"], "available_points" => (1000 - (1000 * $json["limit_used"]))];
	}

	/**
	 * Starts a Transfer.
	 *
	 * @param string Magnet Link or Link to a Torrent File.
	 * @return string The ID of the Transfer.
	 */
	public static function startTransfer($src)
	{
		return Premiumize::request("/transfer/create", ["type" => "torrent", "src" => $src])["id"];
	}

	/**
	 * Lists All Transfers.
	 *
	 * @return array<PremiumizeTransfer> Array of PremiumizeTransfer Objects.
	 */
	public static function getTransfers()
	{
		$transfers = [];
		foreach(Premiumize::request("/transfer/list")["transfers"] as $rawTransfer)
		{
			$transfer = new PremiumizeTransfer();
			$transfer->id = $rawTransfer["id"];
			$transfer->name = $rawTransfer["name"];
			$transfer->hash = $rawTransfer["hash"];
			$transfer->status = PremiumizeTransferStatus::fromName($rawTransfer["status"]);
			$transfer->bytes = $rawTransfer["size"];
			$transfer->type = PremiumizeFileType::fromName($rawTransfer["type"]);
			$transfer->message = $rawTransfer["message"];
			$transfer->up_speed = $rawTransfer["speed_up"];
			$transfer->down_speed = $rawTransfer["speed_down"];
			$transfer->eta = $rawTransfer["eta"];
			$transfer->seeder = $rawTransfer["seeder"];
			$transfer->leacher = $rawTransfer["leacher"];
			$transfer->ratio = $rawTransfer["ratio"];
			$transfer->progress = $rawTransfer["progress"];
			array_push($transfers, $transfer);
		}
		return $transfers;
	}

	/**
	 * Clears All Finished Transfers.
	 */
	public static function clearFinishedTransfers()
	{
		Premiumize::request("/transfer/clearfinished");
	}

	/**
	 * Checks the Given Hashes.
	 *
	 * @param array<string> Array of hashes
	 * @return array<PremiumizeTransferStatus,boolean> Array for each hash including a "status" and "transcoded" value.
	 */
	public static function checkHashes($hashes)
	{
		$hashes = [];
		foreach(Premiumize::request("/torrent/checkhashes", ["hashes" => $hashes]) as $hash)
		{
			array_push($hashes, ["status" => PremiumizeTransferStatus::fromName($hash["status"]), "transcoded" => $hash["transcoded"]]);
		}
		return $hashes;
	}

	public static function getRootFolder()
	{
		$folder = new PremiumizeFolder();
		$folder->id = "";
		$folder->name = "root";
		return $folder;
	}

	public static function getFolderContent($id = "")
	{
		$files = [];
		foreach(Premiumize::request("/folder/list", ["id" => $id])["content"] as $rawfile)
		{
			$file;
			if($rawfile["type"] == "torrent")
			{
				$file = new PremiumizeFileTorrent();
			}
			else if($rawfile["type"] == "folder")
			{
				$file = new PremiumizeFolder();
			}
			else
			{
				throw new Exception("Unsupported File Type: ".$rawfile["type"]);
			}
			$file->id = $rawfile["id"];
			$file->name = $rawfile["name"];
			switch($rawfile["type"])
			{
				case "torrent":
				$file->hash = $rawfile["hash"];
				$file->size = $rawfile["size"];
				$file->created_at = $rawfile["created_at"];
				break;
			}
			array_push($files, $file);
			return $files;
		}
	}

	public static function getTorrentContent($hash)
	{
		$torrent = new PremiumizeTorrent();
		if($rawtorrent = Premiumize::request("/torrent/browse", ["hash" => $hash]))
		{
			$torrent->download = $rawtorrent["zip"];
			$torrent->size = $rawtorrent["size"];
			$torrent->content = Premiumize::recurseTorrentContent($rawtorrent["content"]);
		}
		return $torrent;
	}

	private static function recurseTorrentContent($rawchildren)
	{
		$children = [];
		foreach($rawchildren as $rawchild)
		{
			$child;
			if($rawchild["type"] == "dir")
			{
				$child = new PremiumizeTorrentDirectory();
				$child->download = $rawchild["zip"];
			}
			else
			{
				$child = new PremiumizeTorrentFile();
			}
			$child->name = $rawchild["name"];
			$child->size = $rawchild["size"];
			if($rawchild["type"] == "dir")
			{
				$child->children = Premiumize::recurseTorrentContent($rawchild["children"]);
			}
			else
			{
				$child->download = $rawchild["url"];
				$child->width = $rawchild["width"];
				$child->height = $rawchild["height"];
				$child->duration = $rawchild["duration"];
				$child->transcoded = $rawchild["transcoded"];
			}
			array_push($children, $child);
		}
		return $children;
	}
}

abstract class PremiumizeTransferStatus
{
	const WAITING = 0;
	const FINISHED = 1;
	const SEEDING = 2;
	const TIMEOUT = 3;
	const ERROR = 4;

	public static function fromName($name)
	{
		if($name == "waiting")
		{
			return PremiumizeTransferStatus::WAITING;
		}
		else if($name == "finished")
		{
			return PremiumizeTransferStatus::FINISHED;
		}
		else if($name == "seeding")
		{
			return PremiumizeTransferStatus::SEEDING;
		}
		else if($name == "timeout")
		{
			return PremiumizeTransferStatus::TIMEOUT;
		}
		return PremiumizeTransferStatus::ERROR;
	}
}

abstract class PremiumizeFileType
{
	const FOLDER = 0;
	const TORRENT = 1;

	public static function fromName($name)
	{
		if($name == "folder")
		{
			return PremiumizeTransferStatus::FOLDER;
		}
		else if($name == "torrent")
		{
			return PremiumizeTransferStatus::TORRENT;
		}
	}
}

class PremiumizeTransfer
{
	public $id;
	public $name;
	public $hash;
	/**
	 * Size of the Transfer in bytes.
	 *
	 * @var integer
	 */
	public $size;
	/**
	 * Status of the Transfer.
	 *
	 * @var PremiumizeTransferStatus
	 */
	public $status;
	/**
	 * Type of the Transfer.
	 *
	 * @var PremiumizeFileType
	 */
	public $type;
	public $message;
	public $up_speed;
	public $down_speed;
	public $eta;
	public $seeder;
	public $leacher;
	public $ratio;
	/**
	 * Progress as a percentage (0.0 - 1.0)
	 *
	 * @var float
	 */
	public $progress;

	/**
	 * Deletes or aborts this Transfer.
	 */
	public function delete()
	{
		Premiumize::request("/transfer/delete", ["id" => $this->id, "type" => $this->type]);
	}
}


class PremiumizeTorrent
{
	public $content;
	/**
	 * Size of the Torrent in Bytes.
	 *
	 * @var integer
	 */
	public $size;
	public $download;
}

abstract class PremiumizeAbstractTorrentFile
{
	public $name;
	/**
	 * Size of the File in Bytes.
	 *
	 * @var integer
	 */
	public $size;
	public $download;
}

class PremiumizeTorrentDirectory extends PremiumizeAbstractTorrentFile
{
	public $children;
}

class PremiumizeTorrentFile extends PremiumizeAbstractTorrentFile
{
	/**
	 * The File Extension.
	 * The file name does include the extension too. However, this variable is probably used for loading icons.
	 *
	 * @var string
	 */
	public $ext;
	/**
	 * The MIME Type of the file.
	 *
	 * @var string
	 */
	public $mime;
	public $width;
	public $height;
	public $duration;
	public $transcoded;
}

abstract class PremiumizeFile
{
	/**
	 * ID of the File.
	 *
	 * @var integer
	 */
	public $id;
	/**
	 * Name of the File.
	 *
	 * @var string
	 */
	public $name;

	private $content = null;

	abstract public function getContent();

	/**
	 * Deletes the File.
	 */
	abstract public function delete();
}

class PremiumizeFileTorrent extends PremiumizeFile
{
	public $hash;
	/**
	 * Size of the File in Bytes.
	 *
	 * @var string
	 */
	public $size;
	/**
	 * Timestamp of when the torrent was created at.
	 *
	 * @var string
	 */
	public $created_at;

	/**
	 * Returns the PremiumizeTorrent object for the File.
	 *
	 * @return PremiumizeTorrent PremiumizeTorrent object of the File.
	 */
	public function getContent()
	{
		if($this->content === null)
		{
			$this->content = Premiumize::getTorrentContent($this->hash);
		}
		return $this->content;
	}

	public function delete()
	{
		Premiumize::request("/item/delete", ["type" => "torrent", "id" => $this->id]);
	}
}

class PremiumizeFolder extends PremiumizeFile
{
	/**
	 * Returns an array of Files contained in this Folder.
	 *
	 * @return array<PremiumizeFile> Array of contained Files.
	 */
	public function getContent()
	{
		if($this->content === null)
		{
			$this->content = Premiumize::getFolderContent($this->id);
		}
		return $this->content;
	}

	public function renameTo($newname)
	{
		if($this->id == "")
		{
			throw new Exception("Can't rename root folder.");
		}
		Premiumize::request("/folder/rename", ["id" => $this->id, "newname" => $newname]);
	}

	public function delete()
	{
		Premiumize::request("/folder/delete", ["id" => $this->id]);
	}
}
?>