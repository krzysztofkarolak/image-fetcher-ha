# Image Fetcher

Image Fetcher is a PHP script designed to fetch images from various sources such as NASA APOD, Google Cloud Storage, and Home Assistant Dashboard, making it ideal for use with smart frames like Inky Frame (e-ink or digital screens). This repository includes all necessary components to deploy and run the app.

## Features

- Fetch images from multiple sources:
  - NASA Astronomy Picture of the Day (APOD)
  - Google Cloud Storage Buckets
  - Home Assistant Dashboard
- Integration with Home Assistant for seamless automation
- Customizable settings to suit different display devices

## Repository Structure

- `helm/` - Contains Helm chart for deploying the app
- `image/app/` - Core application code
- `image/etc/` - Configuration files
- `image/Dockerfile` - Dockerfile for containerizing the application

## Getting Started

### Prerequisites

- PHP 8.3 or later
- K8s cluster or Docker (optional, for containerized deployment)
- Home Assistant instance
- Google Cloud Storage bucket (optional, for storing images)

### Installation

1. **Clone the repository:**
   ```sh
   git clone https://github.com/krzysztofkarolak/image-fetcher-ha.git
   cd image-fetcher-ha

2. **Set up environment variables:**
   The application requires several environment variables to be set. These can be configured using helm values and k8s secrets.

3. **Run the application:**
   - **Locally:**
     ```sh
     php image/app/index.php
     ```
   - **Using Docker:**
     ```sh
     docker build -t image-fetcher-ha image
     docker run --env-file .env image-fetcher-ha
     ```

### Configuration

Modify the configuration files in the helm values to match your setup and preferences.

### Usage

After setting up and running the application, images will be fetched according to your configuration and displayed on your smart frame device.

## Contributing

We welcome contributions to enhance the Image Fetcher application. Please fork the repository and submit pull requests for review.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [NASA APOD](https://apod.nasa.gov/apod/astropix.html)
- [Google Cloud Storage](https://cloud.google.com/storage)
- [Home Assistant](https://www.home-assistant.io/)
