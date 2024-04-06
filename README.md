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

Create a configmap for the database service with the content below and apply it with `kubectl apply -f database-configmap.yaml`. The sql script will be mounted as a file in the container to set up the database and its contents at startup.

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-config
data:
  db-load-script.sql: |
    USE ecomdb;
    CREATE TABLE products (id mediumint(8) unsigned NOT NULL auto_increment,Name varchar(255) default NULL,Price varchar(255) default NULL, ImageUrl varchar(255) default NULL,PRIMARY KEY (id)) AUTO_INCREMENT=1;
    INSERT INTO products (Name,Price,ImageUrl) VALUES ("Laptop","100","c-1.png"),("Drone","200","c-2.png"),("VR","300","c-3.png"),("Tablet","50","c-5.png"),("Watch","90","c-6.png"),("Phone Covers","20","c-7.png"),("Phone","80","c-8.png"),("Laptop","150","c-4.png");
```

Create deployment manifest for the database service and apply it with `kubectl apply -f database-deployment.yaml`:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-db-deployment
  labels:
    app: ecom-db
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-db
  template:
    metadata:
      labels:
        app: ecom-db
    spec:
      containers:
      - name: ecomdb
        image: mariadb:latest
        env:
        - name: MARIADB_USER
          value: ecomuser
        - name: MARIADB_PASSWORD
          value: ecompassword
        - name: MARIADB_DATABASE
          value: ecomdb
        - name: MARIADB_ROOT_PASSWORD
          value: ecompassword
        volumeMounts:
          - name: ecom-config-vol
            mountPath: /docker-entrypoint-initdb.d
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
```

Finally create the manifest file `website-deployment.yaml` for the web service and apply it.

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v1
        ports:
        - containerPort: 80
        env:
        - name: DB_HOST
          value: ecom-db-service
        - name: DB_USER
          value: ecomuser
        - name: DB_PASSWORD
          value: ecompassword
        - name: DB_NAME
          value: ecomdb
```

### Expose website

Create service manifest with the default clusterIP type for the database and apply it with `kubectl apply -f database-service.yaml`. This makes it possible the web app to reach its backend database via its name.

```
apiVersion: v1
kind: Service
metadata:
  name: ecom-db-service
spec:
  selector:
    app: ecom-db
  ports:
    - protocol: TCP
      port: 3306
      targetPort: 3306
```

Create a service of type LoadBalancer for the web application using the below manifest to expose it to the public.

```
apiVersion: v1
kind: Service
metadata:
  name: ecom-web-service
spec:
  type: LoadBalancer
  selector:
    app: ecom-web
  ports:
    - protocol: TCP
      port: 80
```

After the service is created you should be able to get the public IP of your application and reach it via the following commands:

```
IP=$(kubectl get service ecom-service --output jsonpath='{.status.loadBalancer.ingress[0].ip}')

curl http://$IP
```

### Implement configuration management


