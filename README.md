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
- **Helm** to package the application

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
  name: ecom-db-config
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

To automate scaling based on CPU usage we will implement Horizontal Pod Autoscaler (HPA). To make HPA work we need to set CPU requests and limits in the deployment definition the following way:

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
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
```

After setting the CPU resources we can add HPA to the deployment using the following command:

```
kubectl autoscale deployment ecom-web-deployment --cpu-percent=50 --min=2 --max=10
```

To simulate some load on the application we can utilize Apache Bench. We can install and run the tool on the local machine with the following commands:

```
# install Apache Bench
sudo apt-get install apache2-utils

# get public IP of our service
IP=$(kubectl get service ecom-web-service --output jsonpath='{.status.loadBalancer.ingress[0].ip}')

# run Apache Bench
ab -r -n 100000 -c 500 http://$IP/
```

We can monitor the behaviour of HPA with the command `kubectl get hpa`.

### Implement Liveness, Readiness and Startup probes

To implement probes we need new endpoints in our web application.

To add the endpoint for Liveness probes create a file named `healthcheck.php` with the code below. It returns HTTP status code 200 to the caller if the application is healthy.

```
<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Define the health check status
$healthCheckStatus = array(
    'status' => 'OK'
);

// Set the HTTP status code to 200 OK
http_response_code(200);

// Encode the status array as JSON and output it
echo json_encode($healthCheckStatus);

?>
```

The following code will be used as the Readiness and Startup probe endpoint. It tries to connect to the database of the ecom-db-deployment. In case of failure it returns HTTP 503, otherwise it returns HTTP 200. File `ready.php`:

```
<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Check if the application dependencies are ready
$dbHost = getenv('DB_HOST');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$dbName = getenv('DB_NAME');

// Attempt to connect to the database
$link = @mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$link) {
    $isReady = false;
} else {
    $isReady = true;
}

// Define the readiness status based on the check result
$readinessStatus = array(
    'status' => $isReady ? 'OK' : 'NOT_READY',
);

// Set the HTTP status code based on readiness status
http_response_code($isReady ? 200 : 503); // 200 OK if ready, 503 Service Unavailable if not ready

// Encode the status array as JSON and output it
echo json_encode($readinessStatus);

?>
```

After we added the endpoints we can build and push a new version of the images.

To use the endpoints in our application we need to update the deployment manifest and the deployment itself:

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
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
        livenessProbe:
          httpGet:
            path: /healthcheck.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
        startupProbe:
          httpGet:
            path: /ready.php
            port: 80
          failureThreshold: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
```

### Utilize ConfigMaps and Secrets

Similarly to other kubernetes compontents we can create secrets in several ways. One of our options is to create them using `kubectl` commands:

```
kubectl create secret generic mariadb-password --from-literal=password='<secret>'

kubectl create secret generic mariadb-root-password --from-literal=password='<secret>'

kubectl create secret generic db-password --from-literal=password='<secret>'
```

We can create secrets using manifest files as well. In this case we have to take care of the base64 encoding of secrets using the `echo -n '<secret>' | base64` command. We can then put the encoded secrets into the manifest files:

database-secret.yaml:

```
apiVersion: v1
kind: Secret
metadata:
  name: ecom-db-secret
data:
  mariadb-password: ZWNvbXBhc3N3b3JkCg==
  mariadb-root-password: ZWNvbXBhc3N3b3JkCg==
```

website-secret.yaml:

```
apiVersion: v1
kind: Secret
metadata:
  name: ecom-web-secret
data:
  db-password: ZWNvbXBhc3N3b3JkCg==
```

To put all the configuration parameters into ConfigMaps first update the `database-configmap.yaml` manifest:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-db-config
data:
  mariadb_user: "ecomuser"
  mariadb_database: "ecomdb"
  db-load-script.sql: |
    USE ecomdb;
    CREATE TABLE products (id mediumint(8) unsigned NOT NULL auto_increment,Name varchar(255) default NULL,Price varchar(255) default NULL, ImageUrl varchar(255) default NULL,PRIMARY KEY (id)) AUTO_INCREMENT=1;
    INSERT INTO products (Name,Price,ImageUrl) VALUES ("Laptop","100","c-1.png"),("Drone","200","c-2.png"),("VR","300","c-3.png"),("Tablet","50","c-5.png"),("Watch","90","c-6.png"),("Phone Covers","20","c-7.png"),("Phone","80","c-8.png"),("Laptop","150","c-4.png");
```

Then create the file `website-configmap.yaml` with the following content:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-web-config
data:
  db_host: "ecom-db-service"
  db_user: "ecomuser"
  db_name: "ecomdb"
```

After applying all the secret and configMap manifests update the deployments:

database-deployment.yaml:

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
        - name: MARIADB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-password
        - name: MARIADB_ROOT_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-root-password
        - name: MARIADB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_user
        - name: MARIADB_DATABASE
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_database
        volumeMounts:
          - name: ecom-db-config-vol
            mountPath: /docker-entrypoint-initdb.d
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
```

website-deployment.yaml:

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
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-web-secret
              key: db-password
        - name: DB_HOST
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_host
        - name: DB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_user
        - name: DB_NAME
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_name
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
        livenessProbe:
          httpGet:
            path: /healthcheck.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
        startupProbe:
          httpGet:
            path: /ready.php
            port: 80
          failureThreshold: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
```

### Package application in Helm

Create a new chart scaffold using Helm CLI:

```
helm create ecom
cd ecom/
```

Customize chart configuration by modifiying the `values.yaml` file. Define variables that you would like to make configurable.

As the next step place the kubernetes manifest files inside the `templates` directory of the Helm chart and parameterize them using Go templates. Replace static values with variables defined in the `values.yaml` file.

Once done with parameterization analyze and package the chart:

```
helm lint
helm package .
```

Finally install the chart using the following command:

```
helm install ecom-release ecom-0.1.0.tgz --namespace ecom --create-namespace
```

You can remove the installed Helm release from your cluster with:

```
helm uninstall ecom-release --namespace ecom
kubectl delete ns ecom
```

### Implement persistent storage

To use persistent storage for the database service first create a persistent volume claim with the following manifest:

database-pvc.yaml:

```
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: ecom-db-pvc
spec:
  accessModes:
  - ReadWriteOnce
  storageClassName: managed-csi
  resources:
    requests:
      storage: 1Gi
```

To mount the storage modify the `database-deployment.yaml`:

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
        - name: MARIADB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-password
        - name: MARIADB_ROOT_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-root-password
        - name: MARIADB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_user
        - name: MARIADB_DATABASE
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_database
        volumeMounts:
          - name: ecom-db-config-vol
            mountPath: /docker-entrypoint-initdb.d
          - name: ecom-db-data-vol
            mountPath: /var/lib/mysql
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
      - name: ecom-db-data-vol
        persistentVolumeClaim:
          claimName: ecom-db-pvc
```

### Implement CI/CD pipeline

To successfully setup the pipeline for building, pushing and deploying the images we have to do some preparation work.

First of all we need a service principal in Azure that has permissions to interact with the Azure control plane. To create it execute the following commands after assigning values to the variables:

```
RGNAME=<resourcegroupname>	

RGID=$(az group show --name $RGNAME --query id --output tsv)

az ad sp create-for-rbac --name gh-action --role Contributor --scopes $RGID
```

The output of the command and the Azure Portal can be used to populate the AZURE_CREDENTIALS repository variable. It should contain the following information in the following format:

```
{
    "clientSecret":  "<ServicePrincipalClientSecret>",
    "subscriptionId":  "<AzureSubscriptionId",
    "tenantId":  "<AzureTenantId>",
    "clientId":  "<ServicePrincipalClientId>"
}
```

 Once the service principal exists create the following GitHub secrets (repository -> settings -> security -> secrets and variables -> actions -> repository secrets):

- **DOCKER_USERNAME** - to store the Docker Hub username
- **DOCKER_PASSWORD** - to store the Docker Hub password
- **AZURE_CREDENTIALS** - to store the Azure Credentials

Then create the following GitHub variables (repository -> settings -> security -> secrets and variables -> actions -> variables -> repository variables):

- **AKS_CLUSTERNAME** - to store the AKS cluster name
- **AKS_RGNAME** - to store the resource group of the AKS cluster

If the preparation is done we can create the `deploy.yml` file under the `.github/workflows` directory to define our workflow.

The wokflow has been built with in a multi job structure. The first job logs in to Docker Hub, extracts metadata to be used for tagging, builds and pushes the image to Docker Hub. The second job installs kubectl, logs in to Azure, sets kubernetes context, creates kubernetes manifest from the Helm chart and deploys the manifest files.

