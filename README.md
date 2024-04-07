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

To create the feature toggle functionality that enables the dark mode in the web application we can create a separate `style-dark.css` file and change the colors in it according to our liking.

We can then set the current stylesheet file dynamically using some PHP code based on the value of an environment variable:

```
<?php
        $featureDarkMode = getenv('FEATURE_DARK_MODE');

        if ($featureDarkMode == "true") {
            echo '<link href="css/style-dark.css" rel="stylesheet">';
        } else {
            echo '<link href="css/style.css" rel="stylesheet">';
        }
?>
```

After building and pushing of the image from the updated sources we can prepare the configmap that will define the environment variable for the container started by the deployment.

Create `website-feature-toggle-configmap.yaml` and apply it to the cluster:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-web-feature-toggle-config
data:
  feature_dark_mode: "true"
```

Update `website-deployment.yaml` to include the environment variable from the configmap and apply it to the cluster:

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
        image: <dockerhubusername>/ecom-web:v2
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
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
```

After the deployment is updated you should be able to see the new style of your web app.

### Scale the application

To manually scale the replica count of the web deployment you can use the following kubectl command:

```
# get original pod count
kubectl get pods

# scale deployment
kubectl scale deployment/ecom-web-deployment --replicas=6

# get new pod count
kubectl get pods
```

### Perform rolling update of the application

To test the rolling update funtionality of the kubernetes deployment add some changes to the application code. 

As an example you can add a new banner to the `index.php` file:
```
<div class="banner">
    <h2 class="banner" style="visibility: visible;">Site wide 15% discount only today!</h2>
</div>
```

And add some animation to both `sytle.css` and `style-dark.css` stylesheet files:

```
h2.banner {
  text-align: center;
  color: gold;
  padding: 1%;
  font-size: 30px;
  animation: blinker 3s linear infinite;
  animation-delay: 0.8s;
  opacity: 1;
}

@keyframes blinker {
  50% {
    opacity: 0;
  } 
}
```

Using the updated application code build and push the image to the resistry and update the deployment manifest with the new image version
To apply and monitor the rollout execute the following commands:

```
kubectl apply -f website-deployment.yaml

kubectl rollout status deployment/ecom-web-deployment
```

### Roll back deployment

Suppose that we introduced a bug with the new version and we would like to restore the previous state of the application. To do this execute the following command:

```
kubectl rollout undo deployment/ecom-web-deployment
```

### Autoscale application