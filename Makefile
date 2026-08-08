# $FreeBSD$

PORTNAME=	pfSense-pkg-CertDeploy
PORTVERSION=	2026.8.1
PORTREVISION=	0
CATEGORIES=	security
MASTER_SITES=	# empty
DISTFILES=	# empty
EXTRACT_ONLY=	# empty

MAINTAINER=	noreply@github.com
COMMENT=	Certificate deployment service for pfSense

LICENSE=	APACHE20

NO_BUILD=	yes
NO_MTREE=	yes

SUB_FILES=	pkg-install pkg-deinstall
SUB_LIST=	PORTNAME=${PORTNAME}

.include <bsd.port.pre.mk>

.include <bsd.port.post.mk>
