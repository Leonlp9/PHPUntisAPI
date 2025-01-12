# Untis PHP API

This project provides a PHP API to interact with the WebUntis system. It allows you to fetch master data, timetables, homeworks, and text data from the WebUntis system.

## Requirements

- PHP 7.0 or higher
- cURL extension enabled

## Installation

1. Clone the repository:
    ```sh
    git clone https://github.com/yourusername/untis-php-api.git
    cd untis-php-api
    ```

2. Ensure the `untisAPI.php` file is accessible from your web server.

## Usage

### HTML Interface

Open `example.html` in your web browser. Fill in the required fields (username, secret, school, start time, end time) and click the desired action button to fetch data.

### API Endpoints

You can also interact with the API directly by sending POST requests to `untisAPI.php` with the following parameters:

- `username`: Your WebUntis username
- `secret`: Your WebUntis secret
- `school`: Your school name
- `startTime`: (optional) Start date in `YYYY-MM-DD` format
- `endTime`: (optional) End date in `YYYY-MM-DD` format

#### Available Actions

- `fetchMasterData`: Fetch master data
- `fetchTimetable`: Fetch timetable
- `fetchHomeworks`: Fetch homeworks
- `fetchText`: Fetch text

### Example Request

```sh
curl -X POST -F "username=yourusername" -F "secret=yoursecret" -F "school=yourschool" -F "startTime=2023-01-01" -F "endTime=2023-01-07" "http://yourserver/untisAPI.php?action=fetchTimetable"