# The Kubernetes Resume Challenge

## Introduction

This repository gives an overview of how I completed the [Kubernetes Resume Challenge](https://cloudresumechallenge.dev/docs/extensions/kubernetes-challenge/). I used the sample e-commerce PHP application [kodekloudhub/learning-app-ecommerce](https://github.com/kodekloudhub/learning-app-ecommerce) provided by [KodeKloud](https://kodekloud.com/) and followed the instructions from the challenge guide to reach the end goals. As I usually work with Azure I decided to use AKS as the managed kubernetes hosting platform.

## Prerequisites

In order to follow the journey you should have:

- **Docker** for building, running and pushing Docker images
- **Docker Hub account** to be able to push images to registry
- **Azure account** to be able to create an AKS cluster
- **Azure CLI** to manipulate Azure resources
- **GitHub account** for version control and automation of build and deployment processes via CI/CD pipeline
- Source code of the sample e-commerce app: [kodekloudhub/learning-app-ecommerce](https://github.com/kodekloudhub/learning-app-ecommerce)

## Implementation

### Web application and database containerization

Create a Dockerfile with the following content and place it in the same directory as the web app source is located in:

```
FROM php:7.4-apache
RUN docker-php-ext-install mysqli
COPY . /var/www/html/
EXPOSE 80
```

Execute the following commands to build and push the image:

```
docker build -t <dockerhubusername>/ecom-web:v1 .
docker push <dockerhubusername>/ecom-web:v1
```

As we are using the official MariaDB image we don't need to build it. 

To test the stack locally we can execute the following commands:

```
docker network create mynetwork

docker run --detach --name ecomdb --network mynetwork --env MARIADB_USER=ecomuser --env MARIADB_PASSWORD=ecompassword --env MARIADB_DATABASE=ecomdb --env MARIADB_ROOT_PASSWORD=ecompassword -v ./assets/db-load-script.sql:/docker-entrypoint-initdb.d/db-load-script.sql mariadb:latest

docker run --detach --name ecomweb --network mynetwork --publish 80:80 --env DB_HOST=ecomdb --env DB_USER=ecomuser --env DB_PASSWORD=ecompassword --env DB_NAME=ecomdb <dockerhubusername>/ecom-web:v1
```

When the stack is started we can have a look at it in our favorite browser at http://localhost.

To clean everything up execute
```
docker container rm -f  $(docker ps -aq)
docker network rm mynetwork
```

### Set up managed Kubernetes cluster on Azure

The most simple way of setting up an AKS cluster is probably via Azure CLI.

First run `az login` to authenticate to Azure.

Then run the following commands to set up some variables, create resource group and deploy cluster:
```
RGNAME=<resourcegroupname>
LOCATION=<region>
CLUSTERNAME=<clustername>

az group create --name $RGNAME --location $LOCATION
		
az aks create --resource-group $RGNAME --name $CLUSTERNAME --enable-managed-identity --node-count 1 --node-vm-size Standard_B2ms --generate-ssh-keys
```

Run the following commands to install kubectl and get the kubernetes credentials needed for cluster authenticaton:
```
az aks install-cli
		
az aks get-credentials --resource-group $RGNAME --name $CLUSTERNAME
```

Finally run `kubectl get nodes` to list the newly created kubernetes worker node.

### Deploy the website to Kubernetes

